<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * TigerWHM — the cPanel page ("Install Tiger"). Runs as the ACCOUNT USER under cPanel's LivePHP.
 * Lists the account's Tigers (whoever installed them), and installs a new one on a chosen domain
 * or a new subdomain in one click: database via UAPI, then the tiger-headless engine under the
 * domain's own PHP. No install logic lives here.
 */
require_once '/usr/local/cpanel/php/cpanel.php';
define('TIGERWHM_ENGINE', '/opt/tigerwhm/engine');
require_once '/opt/tigerwhm/lib/autoload.php';

$cpanel = new CPANEL();
$home   = rtrim((string) (getenv('HOME') ?: $_SERVER['HOME'] ?? ''), '/');
$user   = (string) (getenv('REMOTE_USER') ?: $_SERVER['REMOTE_USER'] ?? posix_getpwuid(posix_geteuid())['name']);
$cfg    = TigerWHM_Config::load();
$api    = TigerWHM_Cpanel::live($cpanel);
$acct   = new TigerWHM_Account($api, $cfg, $home, $user);
$req    = TigerWHM_Http::fromEnv();
$nonceF = $home . '/.tigerwhm/nonce';
$nonce  = TigerWHM_Http::nonce($nonceF);
$e      = ['TigerWHM_Http', 'e'];
$phpNew = TigerWHM_Engine::newestPhp($cfg['min_php']);

$notice = null; $result = null; $problems = [];

// ---------------------------------------------------------------------------------------- act
if ($req->method === 'POST' && $req->p('action') === 'install') {
    if (!TigerWHM_Http::nonceOk($nonceF, $req->p('nonce'))) {
        $problems[] = 'The form expired — please try again.';
    } else {
        $form = [
            'domain' => $req->p('domain'), 'new_sub' => $req->p('new_sub'), 'site_name' => $req->p('site_name'),
            'username' => $req->p('username'), 'email' => $req->p('email'), 'password' => (string) ($req->post['password'] ?? ''),
            'password2' => (string) ($req->post['password2'] ?? ''), 'locale' => $req->p('locale', $cfg['locale']),
            'theme' => $req->p('theme'), 'modules' => (array) $req->p('modules', []), 'agent' => $req->p('agent') === '1', 'https' => $req->p('https', '1') === '1',
        ];
        $problems = TigerWHM_Account::formProblems($form);
        if (!$problems) {
            try {
                $domains = $acct->domains();
                $byName  = array_column($domains, null, 'domain');
                if ($form['new_sub'] !== '') {
                    $fqdn = $acct->addSubdomain($form['new_sub'], $domains[0]['domain']);
                    $domains = $acct->domains(); $byName = array_column($domains, null, 'domain');
                    $form['domain'] = $fqdn;
                }
                $domain = $byName[$form['domain']] ?? null;
                if (!$domain) { throw new RuntimeException('That domain is not on this account.'); }
                $php = TigerWHM_Engine::phpFor($domain['php'], $cfg['min_php']) ?: $phpNew;
                if (!$php) { throw new RuntimeException('This domain runs ' . $domain['php'] . '; Tiger needs ' . $cfg['min_php'] . ' or newer. Change it in MultiPHP Manager and try again.'); }

                $engine = new TigerWHM_Engine($php);
                $db     = $acct->dbNamesFor($domain['domain']);
                // Database first: the engine's requirements gate proves the connection, so it needs real
                // credentials. If the gate then fails, what's left behind is one empty database the user
                // can drop from MySQL Databases — nothing was extracted or exposed.
                $acct->provision($db);
                $spec  = $acct->spec($form, $domain, $db);
                $check = $engine->check($spec);
                if (empty($check['ok'])) {
                    throw new RuntimeException('Requirements: ' . ($check['error']['message'] ?? 'unknown'));
                }
                $result = $engine->install($spec);
            } catch (Throwable $ex) {
                $problems[] = $ex->getMessage();
            }
        }
    }
}

// --------------------------------------------------------------------------------------- data
$domains = []; $dirErr = '';
try { $domains = $acct->domains(); } catch (Throwable $ex) { $dirErr = $ex->getMessage(); }
$feed = TigerWHM_Directory::installables($home . '/.tigerwhm');
$mine = $phpNew ? (new TigerWHM_Engine($phpNew))->discover($home, true) : ['installs' => [], 'summary' => []];
$byDocroot = [];
foreach ((array) ($mine['installs'] ?? []) as $i) { if (!empty($i['docroot'])) { $byDocroot[rtrim($i['docroot'], '/')] = $i; } }

print $cpanel->header('Tiger Management');
?>
<style>
.tg-wrap{max-width:960px}.tg-brand{color:#6c757d;margin-bottom:1rem}.tg-card{background:#fff;border:1px solid #dee2e6;border-radius:.375rem;padding:1.25rem;margin-bottom:1.25rem}
.tg-ok{color:#198754}.tg-bad{color:#dc3545}.tg-muted{color:#6c757d}.tg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:.5rem 1rem}
.tg-steps li{margin:.15rem 0}.tg-nowrap{white-space:nowrap}.tg-row{display:flex;gap:1rem;flex-wrap:wrap;align-items:center}.tg-row>label{min-width:220px}
table.tg{width:100%;border-collapse:collapse}table.tg th,table.tg td{padding:.4rem .5rem;border-bottom:1px solid #eee;text-align:left}
</style>
<div class="tg-wrap">
<?php if ($cfg['branding'] !== ''): ?><p class="tg-brand"><?= $e($cfg['branding']) ?></p><?php endif; ?>

<?php if ($result): ?>
  <div class="tg-card">
    <?php if (!empty($result['ok']) && empty($result['already_installed'])): ?>
      <h3 class="tg-ok">Tiger is installed</h3>
      <p>Sign in at <a href="<?= $e($result['admin_url']) ?>" target="_blank"><?= $e($result['admin_url']) ?></a> as <strong><?= $e($result['login']['email'] ?? '') ?></strong> with the password you chose.</p>
      <?php if (!empty($result['agent']['token'])): ?>
        <p><strong>AI agent credential</strong> (shown once — copy it now): <code><?= $e($result['agent']['token']) ?></code><br>Endpoint: <code><?= $e($result['agent']['endpoint']) ?></code></p>
      <?php endif; ?>
    <?php elseif (!empty($result['ok'])): ?>
      <h3 class="tg-ok">Tiger was already installed here</h3>
      <p>Version <?= $e($result['version']) ?>. Sign in at <a href="<?= $e($result['admin_url']) ?>" target="_blank"><?= $e($result['admin_url']) ?></a>.</p>
    <?php else: ?>
      <h3 class="tg-bad">The install stopped at “<?= $e($result['error']['step'] ?? '?') ?>”</h3>
      <p><?= $e($result['error']['message'] ?? '') ?></p>
      <p class="tg-muted">Fix what it names and submit again — it resumes from that step. Nothing is web-reachable until every step passes.</p>
    <?php endif; ?>
    <details><summary>Steps</summary><ul class="tg-steps">
      <?php foreach ((array) ($result['steps'] ?? []) as $s): ?><li><code><?= $e($s['step']) ?></code> <span class="<?= $s['status'] === 'failed' ? 'tg-bad' : 'tg-ok' ?>"><?= $e($s['status']) ?></span> <span class="tg-muted"><?= $e($s['detail']) ?></span></li><?php endforeach; ?>
    </ul></details>
  </div>
<?php endif; ?>

<?php if ($problems): ?>
  <div class="tg-card" style="border-color:#dc3545"><strong class="tg-bad">Please fix:</strong><ul><?php foreach ($problems as $p): ?><li><?= $e($p) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="tg-card">
  <h3>Your Tigers</h3>
  <?php if (empty($mine['installs'])): ?>
    <p class="tg-muted">No Tiger installs found in this account yet.</p>
  <?php else: ?>
    <table class="tg"><thead><tr><th>Site</th><th>Version</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($mine['installs'] as $i): $dom = ''; foreach ($domains as $d) { if (rtrim($d['docroot'], '/') === rtrim((string) $i['docroot'], '/')) { $dom = $d['domain']; } } ?>
      <tr>
        <td><?= $e($dom ?: basename(dirname((string) $i['app_root']))) ?><br><span class="tg-muted"><?= $e($i['app_root']) ?></span></td>
        <td><?= $e($i['version']) ?><?php if (!empty($i['update_available'])): ?> <span class="tg-muted">(<?= $e($i['latest']) ?> available — your host updates from WHM)</span><?php endif; ?></td>
        <td><?= $i['installed'] === true ? '<span class="tg-ok">live</span>' : ($i['installed'] === null ? '<span class="tg-muted">unknown</span>' : '<span class="tg-bad">not finished</span>') ?></td>
        <td><?php if ($dom): ?><a href="https://<?= $e($dom) ?>/admin" target="_blank">Admin →</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
</div>

<div class="tg-card">
  <h3>Install Tiger</h3>
  <?php if (!$phpNew): ?>
    <p class="tg-bad">This server has no PHP <?= $e($cfg['min_php']) ?> or newer available. Ask your host.</p>
  <?php else: ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="action" value="install"><input type="hidden" name="nonce" value="<?= $e($nonce) ?>">
    <div class="tg-row"><label>Where</label>
      <select name="domain">
        <option value="">— choose a domain —</option>
        <?php foreach ($domains as $d): $has = isset($byDocroot[rtrim($d['docroot'], '/')]); ?>
          <option value="<?= $e($d['domain']) ?>" <?= (!$d['php_ok'] || $has) ? 'disabled' : '' ?>><?= $e($d['domain']) ?> (<?= $e($d['kind']) ?>, <?= $e($d['php']) ?>)<?= $has ? ' — Tiger already here' : (!$d['php_ok'] ? ' — PHP too old' : '') ?></option>
        <?php endforeach; ?>
      </select>
      <span class="tg-nowrap"><span class="tg-muted">or a new subdomain:</span> <input name="new_sub" placeholder="app" size="12"><?php if ($domains): ?><span class="tg-muted">.<?= $e($domains[0]['domain']) ?></span><?php endif; ?></span>
    </div>
    <div class="tg-row"><label>Site name</label><input name="site_name" placeholder="My Site" size="30"></div>
    <div class="tg-row"><label>Admin email</label><input name="email" type="email" required size="30"></div>
    <div class="tg-row"><label>Admin username <span class="tg-muted">(optional)</span></label><input name="username" size="20"></div>
    <div class="tg-row"><label>Admin password</label><input name="password" type="password" required minlength="12" size="24"> <input name="password2" type="password" placeholder="again" size="24"></div>
    <div class="tg-row"><label>Language</label><select name="locale"><?php foreach (['en' => 'English', 'es' => 'Español', 'pt' => 'Português', 'de' => 'Deutsch', 'fr' => 'Français', 'hi' => 'हिन्दी'] as $k => $v): ?><option value="<?= $k ?>" <?= $k === $cfg['locale'] ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
    <?php if ($feed['available']): ?>
      <p style="margin-top:1rem"><strong>Theme</strong></p>
      <div class="tg-grid">
        <label><input type="radio" name="theme" value="" <?= $cfg['theme'] === '' ? 'checked' : '' ?>> Tiger default</label>
        <?php foreach ($feed['themes'] as $t): ?><label><input type="radio" name="theme" value="<?= $e($t['slug']) ?>" <?= $t['slug'] === $cfg['theme'] ? 'checked' : '' ?>> <?= $e($t['name']) ?> <span class="tg-muted"><?= $e($t['version']) ?></span></label><?php endforeach; ?>
      </div>
      <p style="margin-top:1rem"><strong>Modules</strong></p>
      <div class="tg-grid">
        <?php foreach ($feed['modules'] as $m): ?><label title="<?= $e($m['description']) ?>"><input type="checkbox" name="modules[]" value="<?= $e($m['slug']) ?>" <?= in_array($m['slug'], $cfg['modules'], true) ? 'checked' : '' ?>> <?= $e($m['name']) ?> <span class="tg-muted"><?= $e($m['version']) ?></span></label><?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="tg-muted">The module directory is not reachable right now; Tiger installs with its defaults. Add modules later from Tiger's own Module Manager.</p>
    <?php endif; ?>
    <?php if (!empty($cfg['allow_agent'])): ?>
      <p style="margin-top:1rem"><label><input type="checkbox" name="agent" value="1"> Connect an AI agent (mints a credential for Tiger's MCP endpoint — shown once)</label></p>
    <?php endif; ?>
    <p><label><input type="checkbox" name="https" value="1" checked> The site has HTTPS (run AutoSSL first if not)</label></p>
    <p class="tg-muted">Tiger installs <em>above</em> the document root — your configuration and secrets are never web-reachable. Your existing sites are not touched.</p>
    <button type="submit" class="btn btn-primary">Install Tiger</button>
  </form>
  <?php endif; ?>
</div>
</div>
<?php
print $cpanel->footer();
$cpanel->end();
