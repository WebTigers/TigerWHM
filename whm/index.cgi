#!/usr/local/cpanel/3rdparty/bin/php
<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * TigerWHM — the WHM page (root). The fleet: every Tiger on the server with its account, domain,
 * version and update state; update one or all (each as its account user, under its vhost's PHP);
 * the host defaults every account's install form starts from; and the accounts whose PHP is below
 * the minimum. cpsrvd authenticates and applies the ACL before this runs; REMOTE_USER is the WHM user.
 */
define('TIGERWHM_ENGINE', '/opt/tigerwhm/engine');
require_once '/opt/tigerwhm/lib/autoload.php';
ini_set('display_errors', 'stderr');

TigerWHM_Http::cgiHeader();
$req    = TigerWHM_Http::fromEnv();
$cfg    = TigerWHM_Config::load();
$fleet  = new TigerWHM_Fleet($cfg);
$nonceF = '/var/cpanel/tigerwhm/nonce';
$nonce  = TigerWHM_Http::nonce($nonceF);
$e      = ['TigerWHM_Http', 'e'];
$version = trim((string) @file_get_contents('/opt/tigerwhm/VERSION'));
$engineV = '';
if (preg_match("/VERSION\s*=\s*'([^']+)'/", (string) @file_get_contents('/opt/tigerwhm/engine/src/Tiger/Headless/Version.php'), $m)) { $engineV = $m[1]; }

$notice = []; $errors = []; $updates = [];

// ---------------------------------------------------------------------------------------- act
if ($req->method === 'POST') {
    if (!TigerWHM_Http::nonceOk($nonceF, $req->p('nonce'))) {
        $errors[] = 'The form expired — reload and try again.';
    } elseif ($req->p('action') === 'defaults') {
        $c = [
            'theme' => $req->p('theme_follow') === '1' ? null : $req->p('theme'),
            'modules' => $req->p('modules_follow') === '1' ? null : array_values(array_filter(array_map('trim', explode(',', $req->p('modules'))))),
            'skill_packs' => $req->p('packs_follow') === '1' ? null : array_values(array_filter(array_map('trim', explode(',', $req->p('packs'))))),
            'locale' => $req->p('locale'), 'branding' => $req->p('branding'), 'min_php' => $req->p('min_php'),
            'mail' => ['transport' => $req->p('mail_transport'), 'host' => $req->p('mail_host'), 'port' => $req->p('mail_port', '25')],
            'allow_agent' => $req->p('allow_agent') === '1',
            'catalog' => ['mode' => $req->p('catalog_mode'), 'catalog_ref' => $req->p('catalog_ref'), 'directory_ref' => $req->p('directory_ref')],
        ];
        if ($req->p('catalog_mode') === 'pinned' && TigerWHM_Config::normalize($c)['catalog']['mode'] !== 'pinned') { $errors[] = 'Pinned mode needs two full 40-character commit shas (catalog and Directory); kept live.'; }
        if (TigerWHM_Config::save($c)) { $cfg = TigerWHM_Config::load(); $notice[] = 'Host defaults saved.'; }
        else { $errors[] = 'Could not write ' . TigerWHM_Config::PATH; }
    } elseif ($req->p('action') === 'upgrade') {
        $targets = (array) $req->p('app_root', []);
        $all = $fleet->installs(true);
        $byRoot = array_column($all['installs'], null, 'app_root');
        foreach ($targets as $root) {
            if (!isset($byRoot[$root])) { $errors[] = "Not a known install: {$root}"; continue; }
            $updates[$root] = $fleet->upgrade($byRoot[$root]);
        }
    }
}

// --------------------------------------------------------------------------------------- data
$fl    = $fleet->installs(true);
$below = $fleet->belowMinimum();
$sum   = $fl['summary'] + ['live' => 0, 'updates_available' => null, 'latest' => null, 'versions' => []];
$catalog = TigerWHM_Catalog::load('/var/cpanel/tigerwhm', $cfg);
$pre     = TigerWHM_Config::preselect($cfg, $catalog);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Tiger — WHM</title>
<style>
body{font:14px/1.45 -apple-system,Segoe UI,Roboto,sans-serif;margin:0;padding:1.25rem;color:#212529;background:#f8f9fa}
.card{background:#fff;border:1px solid #dee2e6;border-radius:.375rem;padding:1.25rem;margin-bottom:1.25rem;max-width:1200px}
h1{font-size:1.4rem;margin:0 0 .25rem}h2{font-size:1.1rem;margin:0 0 .75rem}.muted{color:#6c757d}.ok{color:#198754}.bad{color:#dc3545}.warn{color:#b45309}
table{width:100%;border-collapse:collapse}th,td{padding:.4rem .5rem;border-bottom:1px solid #eee;text-align:left;vertical-align:top}
.kpi{display:inline-block;margin-right:1.5rem}.kpi b{font-size:1.4rem;display:block}
.row{display:flex;gap:1rem;flex-wrap:wrap;align-items:center;margin:.35rem 0}.row>label{min-width:200px}input[type=text],select{padding:.25rem .4rem}
.btn{padding:.35rem .8rem;border:1px solid #0d6efd;background:#0d6efd;color:#fff;border-radius:.25rem;cursor:pointer}.btn.sec{background:#fff;color:#0d6efd}
code{background:#f1f3f5;padding:0 .25rem;border-radius:.2rem}
</style></head><body>
<div class="card"><h1>Tiger</h1><span class="muted">TigerWHM <?= $e($version) ?> · engine tiger-headless <?= $e($engineV) ?> · every cPanel account on this server has a <b>Tiger Management</b> item in its cPanel left menu.</span></div>

<?php foreach ($notice as $n): ?><div class="card ok"><?= $e($n) ?></div><?php endforeach; ?>
<?php foreach ($errors as $n): ?><div class="card bad"><?= $e($n) ?></div><?php endforeach; ?>

<?php if ($updates): ?>
<div class="card"><h2>Update results</h2><table><thead><tr><th>Install</th><th>Result</th></tr></thead><tbody>
<?php foreach ($updates as $root => $u): ?>
  <tr><td><code><?= $e($root) ?></code></td><td>
    <?php if (!empty($u['ok'])): ?><span class="ok">ok</span> — now <?= $e($u['version'] ?? '') ?><?= !empty($u['already_current']) ? ' (already current)' : '' ?>
    <?php else: ?><span class="bad">failed at <?= $e($u['error']['step'] ?? '?') ?></span> — <?= $e($u['error']['message'] ?? '') ?><?php endif; ?>
    <?php if (!empty($u['steps'])): ?><details><summary class="muted">steps</summary><ul><?php foreach ($u['steps'] as $s): ?><li><code><?= $e($s['step']) ?></code> <?= $e($s['status']) ?> <span class="muted"><?= $e($s['detail']) ?></span></li><?php endforeach; ?></ul></details><?php endif; ?>
  </td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?>

<div class="card">
  <h2>Fleet</h2>
  <?php if (!$fl['ok']): ?><p class="bad">Discovery failed: <?= $e($fl['error']['message'] ?? '') ?></p><?php endif; ?>
  <p><span class="kpi"><b><?= count($fl['installs']) ?></b>installs</span><span class="kpi"><b><?= (int) $sum['live'] ?></b>live sites</span>
     <span class="kpi"><b><?= $sum['updates_available'] === null ? '?' : (int) $sum['updates_available'] ?></b>need an update</span>
     <span class="kpi"><b><?= $e($sum['latest'] ?? '?') ?></b>latest tiger-core</span></p>
  <form method="post"><input type="hidden" name="action" value="upgrade"><input type="hidden" name="nonce" value="<?= $e($nonce) ?>">
  <table><thead><tr><th></th><th>Account</th><th>Domain</th><th>App root</th><th>PHP</th><th>Version</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($fl['installs'] as $i): $needs = !empty($i['update_available']); ?>
    <tr>
      <td><?php if ($needs && $i['installed'] === true && $i['php_bin']): ?><input type="checkbox" name="app_root[]" value="<?= $e($i['app_root']) ?>"><?php endif; ?></td>
      <td><?= $e($i['account']) ?></td>
      <td><?= $i['vhost'] !== '' ? '<a href="https://' . $e($i['vhost']) . '/admin" target="_blank">' . $e($i['vhost']) . '</a>' : '<span class="muted">' . $e($i['docroot'] ?? '—') . '</span>' ?></td>
      <td><code><?= $e($i['app_root']) ?></code><br><span class="muted"><?= $e($i['layout'] ?? '') ?> · db <?= $e($i['db']['name'] ?? '') ?></span></td>
      <td><?= $e($i['php'] ?: '?') ?><?= !$i['php_bin'] ? ' <span class="bad" title="Set this vhost\'s PHP in MultiPHP Manager; updates run under the vhost\'s own PHP only">' . ($i['php'] !== '' ? 'below minimum' : 'unknown') . ' — not updatable</span>' : '' ?></td>
      <td><?= $e($i['version']) ?><?= $needs ? ' <span class="warn">→ ' . $e($i['latest']) . '</span>' : '' ?></td>
      <td><?= $i['installed'] === true ? '<span class="ok">live</span>' : ($i['installed'] === null ? '<span class="muted">unknown: ' . $e($i['db_error'] ?? '') . '</span>' : '<span class="bad">not finished</span>') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$fl['installs']): ?><tr><td colspan="7" class="muted">No Tiger installs found under /home.</td></tr><?php endif; ?>
  </tbody></table>
  <p><button class="btn" type="submit">Update selected</button> <span class="muted">each runs as its own account user under its vhost's PHP; Tiger backs up before swapping</span>
     <button class="btn sec" type="button" onclick="document.querySelectorAll('input[name=\'app_root[]\']').forEach(function(c){c.checked=true})">Select all needing an update</button></p>
  </form>
</div>

<div class="card">
  <h2>Host defaults</h2>
  <p class="muted">What every account's Install Tiger form starts from. Stored in <code><?= $e(TigerWHM_Config::PATH) ?></code> (world-readable — no secrets go here).</p>
  <form method="post"><input type="hidden" name="action" value="defaults"><input type="hidden" name="nonce" value="<?= $e($nonce) ?>">
    <p class="muted">The lists themselves — themes, modules, skill packs — come from the public catalog (<code>WebTigers/TigerCatalog</code>) and Directory (<code>WebTigers/TigerVendors</code>): <b><?= $cfg['catalog']['mode'] === 'pinned' ? 'pinned' : 'live' ?></b>
      <?= $cfg['catalog']['mode'] === 'pinned' ? '— catalog @ <code>' . $e(substr($cfg['catalog']['catalog_ref'], 0, 12)) . '</code>, Directory @ <code>' . $e(substr($cfg['catalog']['directory_ref'], 0, 12)) . '</code>; nothing changes until you move the pins' : '— read from <code>main</code> hourly; what WebTigers adds reaches new installs here with no plugin update' ?>
      (<?= $catalog['live'] ? 'reached' : 'unreachable, using the last copy or the bundled snapshot' ?>). Each row: follow the catalog's default, or set your own.</p>
    <div class="row"><label>Catalog trust</label><select name="catalog_mode"><option value="live" <?= $cfg['catalog']['mode'] === 'live' ? 'selected' : '' ?>>live — follow main</option><option value="pinned" <?= $cfg['catalog']['mode'] === 'pinned' ? 'selected' : '' ?>>pinned — only these commits</option></select></div>
    <div class="row"><label>Pinned commits</label>catalog <input type="text" name="catalog_ref" size="42" value="<?= $e($cfg['catalog']['catalog_ref']) ?>" placeholder="40-hex commit of WebTigers/TigerCatalog"> &nbsp; Directory <input type="text" name="directory_ref" size="42" value="<?= $e($cfg['catalog']['directory_ref']) ?>" placeholder="40-hex commit of WebTigers/TigerVendors"></div>
    <div class="row"><label>Pre-selected theme</label><label><input type="checkbox" name="theme_follow" value="1" <?= $cfg['theme'] === null ? 'checked' : '' ?>> follow the catalog (<?= $e($catalog['featured']['theme'] ?: 'Tiger default') ?>)</label> <input type="text" name="theme" value="<?= $e((string) $cfg['theme']) ?>" placeholder="theme-grey-mist (blank = Tiger default)"></div>
    <div class="row"><label>Pre-ticked modules</label><label><input type="checkbox" name="modules_follow" value="1" <?= $cfg['modules'] === null ? 'checked' : '' ?>> follow the catalog (<?= $e(implode(', ', $catalog['featured']['modules']) ?: 'none') ?>)</label> <input type="text" name="modules" size="40" value="<?= $e(implode(',', (array) $cfg['modules'])) ?>" placeholder="docs,tigershield"></div>
    <div class="row"><label>Pre-ticked skill packs</label><label><input type="checkbox" name="packs_follow" value="1" <?= $cfg['skill_packs'] === null ? 'checked' : '' ?>> follow the catalog (<?= $e(implode(', ', array_column(array_filter($catalog['skill_packs'], fn ($p) => $p['default']), 'id')) ?: 'none') ?>)</label> <input type="text" name="packs" size="40" value="<?= $e(implode(',', (array) $cfg['skill_packs'])) ?>" placeholder="<?= $e(implode(',', array_column($catalog['skill_packs'], 'id'))) ?>"></div>
    <div class="row"><label>Default language</label><select name="locale"><?php foreach (['en','es','pt','de','fr','hi'] as $l): ?><option <?= $l === $cfg['locale'] ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="row"><label>Branding line</label><input type="text" name="branding" size="50" value="<?= $e($cfg['branding']) ?>" placeholder="Provided by Acme Hosting"></div>
    <div class="row"><label>Minimum PHP</label><select name="min_php"><?php foreach (['ea-php81','ea-php82','ea-php83','ea-php84'] as $v): ?><option <?= $v === $cfg['min_php'] ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
    <div class="row"><label>Mail relay</label><select name="mail_transport"><option value="" <?= $cfg['mail']['transport'] === '' ? 'selected' : '' ?>>the box's own MTA</option><option value="smtp" <?= $cfg['mail']['transport'] === 'smtp' ? 'selected' : '' ?>>SMTP relay</option></select>
      <input type="text" name="mail_host" value="<?= $e($cfg['mail']['host']) ?>" placeholder="relay.example.net"> : <input type="text" name="mail_port" size="5" value="<?= $e($cfg['mail']['port']) ?>"> <span class="muted">unauthenticated relay only (a password can't live in a world-readable file)</span></div>
    <div class="row"><label>AI agent connect</label><label><input type="checkbox" name="allow_agent" value="1" <?= !empty($cfg['allow_agent']) ? 'checked' : '' ?>> accounts may mint an MCP credential at install</label></div>
    <p><button class="btn" type="submit">Save defaults</button></p>
  </form>
</div>

<div class="card">
  <h2>Requirements gate</h2>
  <?php if (!$below): ?><p class="ok">Every account's main domain runs <?= $e($cfg['min_php']) ?> or newer.</p>
  <?php else: ?>
    <p>These accounts cannot install Tiger until their PHP is raised in <strong>MultiPHP Manager</strong> (the Install Tiger form tells them so):</p>
    <table><thead><tr><th>Account</th><th>Domain</th><th>PHP</th></tr></thead><tbody>
    <?php foreach ($below as $b): ?><tr><td><?= $e($b['account']) ?></td><td><?= $e($b['vhost']) ?></td><td class="bad"><?= $e($b['version']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
</div>

<div class="card muted">Update the plugin itself: <code>bash &lt;(curl -fsSL https://raw.githubusercontent.com/WebTigers/TigerWHM/main/install.sh)</code> · Remove it: <code>/opt/tigerwhm/uninstall.sh</code></div>
</body></html>
