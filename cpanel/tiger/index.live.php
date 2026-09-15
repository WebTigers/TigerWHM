<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * TigerWHM — the cPanel page ("Tiger AI Site Management"). Runs as the ACCOUNT USER under cPanel's LivePHP.
 *
 * Shape (the same one WP Toolkit trained everyone on): a title + toolbar, the list of installs AS the
 * page (or an empty state), and Install as a slide-over flyout with grouped sections, a label/field
 * grid, generated defaults, and Install/Cancel pinned at the bottom. No install logic lives here —
 * the database is created through UAPI and the tiger-headless engine does the rest under the domain's
 * own PHP.
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

$result = null; $problems = []; $form = []; $catalog = null; $openFlyout = $req->g('open') !== '';

// ---------------------------------------------------------------------------------------- act
if ($req->method === 'POST' && $req->p('action') === 'install') {
    $form = [
        'domain' => $req->p('domain'), 'new_sub' => $req->p('new_sub'), 'site_name' => $req->p('site_name'),
        'username' => $req->p('username'), 'email' => $req->p('email'), 'password' => (string) ($req->post['password'] ?? ''),
        'password2' => (string) ($req->post['password'] ?? ''), 'locale' => $req->p('locale', $cfg['locale']),
        'theme' => $req->p('theme'), 'modules' => (array) $req->p('modules', []), 'agent' => $req->p('agent') === '1', 'https' => $req->p('https', '1') === '1',
        'db_name' => $req->p('db_name'), 'db_user' => $req->p('db_user'), 'db_password' => (string) ($req->post['db_password'] ?? ''),
        'password_generated' => $req->p('password_generated') === '1',
        'packs' => array_values(array_filter(array_map('strval', (array) $req->p('packs', [])))),
    ];
    $catalog = TigerWHM_Catalog::load($home . '/.tigerwhm', $cfg);
    $form['skills'] = TigerWHM_Catalog::skillsFor($catalog, $form['packs']);
    if (!TigerWHM_Http::nonceOk($nonceF, $req->p('nonce'))) {
        $problems[] = 'The form expired — please try again.';
    } else {
        $problems = TigerWHM_Account::formProblems($form);
        if (!$problems) {
            try {
                $domains = $acct->domains();
                $byName  = array_column($domains, null, 'domain');
                if ($form['new_sub'] !== '') {
                    // Idempotent: a retry after a failed install finds the subdomain already there.
                    $fqdn = $acct->addSubdomain($form['new_sub'], $domains[0]['domain']);
                    $domains = $acct->domains(); $byName = array_column($domains, null, 'domain');
                    $form['domain'] = $fqdn; $form['new_sub'] = '';
                }
                $domain = $byName[$form['domain']] ?? null;
                if (!$domain) { throw new RuntimeException('That domain is not on this account.'); }
                // The vhost's OWN PHP, never a fallback: an install that only runs under a CLI the web
                // server does not use is a site that does not load.
                $php = TigerWHM_Engine::phpFor($domain['php'], $cfg['min_php']);
                if (!$php) { throw new RuntimeException('This domain runs ' . ($domain['php'] ?: 'an unknown PHP') . '; Tiger needs ' . $cfg['min_php'] . ' or newer. Change it in MultiPHP Manager and try again.'); }

                $engine  = new TigerWHM_Engine($php);
                $pending = TigerWHM_Pending::load($home, $domain['domain']);
                $reused  = $pending !== null;
                $db      = $reused ? $pending['db'] : $acct->dbOverrides($acct->dbNamesFor($domain['domain']), $form);
                $spec    = $acct->spec($form, $domain, $db);
                new Tiger_Headless_Spec($spec);   // the engine's own validation, BEFORE anything is created
                if (!$reused) {
                    // Database first: the engine's requirements gate proves the connection, so it needs real
                    // credentials. Remembered per domain until the install succeeds, so a retry reuses them.
                    $acct->provision($db);
                    TigerWHM_Pending::save($home, $domain['domain'], $db, $spec['paths']['app_root']);
                }
                $check = $engine->check($spec);
                if (empty($check['ok'])) {
                    // Nothing was extracted or written: a fresh database has nothing worth keeping.
                    if (!$reused) { $left = $acct->rollback($db); TigerWHM_Pending::clear($home, $domain['domain']); }
                    throw new RuntimeException('Requirements: ' . ($check['error']['message'] ?? 'unknown') . (!empty($left) ? ' (could not remove ' . implode(', ', $left) . ')' : ''));
                }
                $result = $engine->install($spec);
                if (!empty($result['ok'])) {
                    TigerWHM_Pending::clear($home, $domain['domain']);
                    TigerWHM_Log::install($home, $domain['domain'], $spec, $result, $cfg, $catalog);
                } elseif ($reused) {
                    $result['reused_db'] = $db['name'];
                }
                if ($reused && !empty($result['ok'])) { $result['reused_db'] = $db['name']; }
            } catch (Throwable $ex) {
                $problems[] = $ex->getMessage();
            }
        }
    }
    $openFlyout = (bool) $problems;   // errors → reopen the flyout with what they typed
}

// The Admin button: mint a one-time sign-in link for the site's founding admin (the engine, as this
// account user) and send the browser straight into /admin — WP Toolkit's "Log in". Before any output.
if ($req->method === 'POST' && $req->p('action') === 'login') {
    $root = $req->p('app_root'); $host = $req->p('host');
    if (!TigerWHM_Http::nonceOk($nonceF, $req->p('nonce'))) {
        $problems[] = 'The form expired — please try again.';
    } elseif (strpos($root, $home . '/') !== 0 || strpos($root, '..') !== false || !preg_match('/^[a-z0-9.-]+$/i', $host)) {
        $problems[] = 'That install is not in this account.';
    } elseif ($phpNew) {
        $login = (new TigerWHM_Engine($phpNew))->login($root);
        if (!empty($login['ok'])) {
            header('Location: https://' . $host . $login['path'], true, 302);
            exit;
        }
        $problems[] = 'Could not sign you in: ' . ($login['error']['message'] ?? 'unknown');
    }
}

// --------------------------------------------------------------------------------------- data
$domains = [];
try { $domains = $acct->domains(); } catch (Throwable $ex) { $problems[] = 'Could not read this account\'s domains: ' . $ex->getMessage(); }
$feed = TigerWHM_Directory::installables($home . '/.tigerwhm', $cfg);
$catalog = $catalog ?? TigerWHM_Catalog::load($home . '/.tigerwhm', $cfg);
$pre     = TigerWHM_Config::preselect($cfg, $catalog);
$mine = $phpNew ? (new TigerWHM_Engine($phpNew))->discover($home, true) : ['installs' => [], 'summary' => []];
$byDocroot = [];
foreach ((array) ($mine['installs'] ?? []) as $i) { if (!empty($i['docroot'])) { $byDocroot[rtrim($i['docroot'], '/')] = $i; } }
$domainFor = static function ($install) use ($domains) {
    foreach ($domains as $d) { if (rtrim($d['docroot'], '/') === rtrim((string) ($install['docroot'] ?? ''), '/')) { return $d['domain']; } }
    return '';
};
$mainDomain = $domains[0]['domain'] ?? '';
$prefix = $user . '_';
try { $prefix = (string) ($api->uapi('Mysql', 'get_restrictions', [])['prefix'] ?? $prefix); } catch (Throwable $ex) { /* keep the guess */ }

print $cpanel->header('Tiger AI Site Management');
?>
<style>
/* Page */
.tg{--tg-ink:#2f363d;--tg-muted:#6c757d;--tg-line:#dfe3e8;--tg-blue:#2f7be0;--tg-ok:#1a7f37;--tg-warn:#b45309;--tg-bad:#c62828;color:var(--tg-ink)}
.tg *{box-sizing:border-box}
.tg-head{display:flex;align-items:baseline;gap:1rem;margin:0 0 .75rem}.tg-head h1{font-size:1.9rem;font-weight:400;margin:0}.tg-head a{color:var(--tg-blue);text-decoration:none;font-size:.95rem}
.tg-toolbar{display:flex;gap:.5rem;align-items:center;padding:.25rem 0 1rem;margin-bottom:.25rem}.tg-help{margin-left:auto;color:var(--tg-blue);text-decoration:none}
.tg-btn{display:inline-block;padding:.5rem 1.1rem;border:1px solid var(--tg-line);background:#f6f7f9;color:var(--tg-ink);border-radius:3px;font:inherit;cursor:pointer;text-decoration:none;line-height:1.3}
.tg-btn:hover{background:#eef0f3}.tg-btn.primary{background:var(--tg-blue);border-color:var(--tg-blue);color:#fff}.tg-btn.primary:hover{background:#2468c4}
.tg-btn[disabled]{opacity:.55;cursor:default}
/* List */
.tg-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--tg-line)}
.tg-table th,.tg-table td{padding:.9rem 1rem;text-align:left;vertical-align:middle;border-bottom:1px solid var(--tg-line)}
.tg-table th{font-weight:600;background:#f6f7f9;font-size:.9rem;color:var(--tg-muted)}.tg-table tr:last-child td{border-bottom:0}
.tg-site{font-weight:600;font-size:1.02rem}.tg-site small{display:block;font-weight:400;color:var(--tg-muted);font-size:.85rem;margin-top:.15rem}
.tg-pill{display:inline-block;padding:.15rem .55rem;border-radius:1rem;font-size:.8rem;font-weight:600}.tg-pill.ok{background:#e6f4ea;color:var(--tg-ok)}.tg-pill.warn{background:#fff4e5;color:var(--tg-warn)}.tg-pill.bad{background:#fdecea;color:var(--tg-bad)}.tg-pill.mute{background:#eef0f3;color:var(--tg-muted)}
.tg-paw{width:64px;height:64px;margin:0 auto 1rem;display:block}
/* Intro (what Tiger is — the cPanel audience is thinking "I need a website") */
.tg-hero{display:flex;gap:1.75rem;align-items:center;background:#fff;border:1px solid var(--tg-line);padding:2rem 2.25rem;margin:1.25rem 0 0}
.tg-hero .tg-paw{width:72px;height:72px;margin:0;flex:0 0 72px}.tg-hero h2{font-size:1.65rem;font-weight:600;margin:0 0 .5rem;line-height:1.2}.tg-hero p{margin:0 0 1.1rem;font-size:1.02rem;max-width:62rem}
.tg-hero.big{padding:3rem 2.5rem;margin-top:0}.tg-hero.big h2{font-size:2.1rem}.tg-hero.big p{font-size:1.1rem}
.tg-hero .tg-cta{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center}.tg-hero .tg-cta a.tg-link{color:var(--tg-blue);text-decoration:none;padding:.5rem .25rem}
.tg-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;margin:1rem 0 0}
.tg-cards>div{background:#fff;border:1px solid var(--tg-line);padding:1.5rem 1.6rem;display:flex;flex-direction:column}
.tg-cards .tg-kicker{font-size:.78rem;font-weight:700;letter-spacing:.08em;color:var(--tg-muted);margin:0 0 .5rem}.tg-cards h3{font-size:1.15rem;font-weight:600;margin:0 0 .6rem}.tg-cards p{margin:0 0 1.1rem;flex:1}
.tg-cards a{color:var(--tg-blue);text-decoration:none;font-weight:600}
.tg-sites-h{font-size:1.1rem;font-weight:600;margin:.5rem 0 .6rem}
.tg-steps-sum{cursor:pointer;list-style:none}.tg-steps-sum::-webkit-details-marker{display:none}.tg-steps-sum::before{content:"▸";display:inline-block;width:1em;transition:transform .15s}details[open]>.tg-steps-sum::before{transform:rotate(90deg)}
@media (max-width:900px){.tg-cards{grid-template-columns:1fr}.tg-hero{flex-direction:column;align-items:flex-start;padding:1.5rem}}
.tg-muted{color:var(--tg-muted)}.tg-ok{color:var(--tg-ok)}.tg-bad{color:var(--tg-bad)}
/* Result / errors */
.tg-card{background:#fff;border:1px solid var(--tg-line);padding:1.25rem 1.5rem;margin:0 0 1rem}.tg-card h3{margin:0 0 .5rem;font-size:1.2rem}.tg-card ul{margin:.5rem 0 0 1.2rem}
.tg-card.bad{border-left:4px solid var(--tg-bad)}.tg-card.ok{border-left:4px solid var(--tg-ok)}
.tg-steps{list-style:none;padding:0;margin:.5rem 0 0}.tg-steps li{padding:.2rem 0;font-size:.9rem}.tg-steps code{font-size:.85rem}
/* Flyout */
.tg-scrim{position:fixed;inset:0;background:rgba(20,26,34,.45);z-index:1040;opacity:0;visibility:hidden;transition:opacity .25s ease,visibility 0s linear .25s}
.tg-fly{position:fixed;top:0;right:0;bottom:0;width:min(780px,100%);background:#fff;z-index:1050;display:flex;flex-direction:column;box-shadow:-8px 0 30px rgba(0,0,0,.18);margin:0;transform:translateX(100%);visibility:hidden;transition:transform .3s cubic-bezier(.4,0,.2,1),visibility 0s linear .3s}
.tg.open .tg-scrim{opacity:1;visibility:visible;transition:opacity .25s ease}.tg.open .tg-fly{transform:none;visibility:visible;transition:transform .3s cubic-bezier(.4,0,.2,1)}
@media (prefers-reduced-motion:reduce){.tg-scrim,.tg-fly{transition:none}}
.tg-fly-head{padding:1.5rem 2rem 1rem;border-bottom:1px solid var(--tg-line);position:relative}.tg-fly-head h2{font-size:1.8rem;font-weight:400;margin:0}.tg-fly-head p{margin:.25rem 0 0;color:var(--tg-ink)}
.tg-x{position:absolute;right:1.5rem;top:1.4rem;border:0;background:none;font-size:1.6rem;line-height:1;color:var(--tg-muted);cursor:pointer}
.tg-fly-body{padding:1.25rem 2rem;overflow:auto;flex:1}
.tg-fly-foot{padding:1rem 2rem;border-top:1px solid var(--tg-line);display:flex;gap:.6rem;background:#fff}
.tg-hint{color:var(--tg-ink);margin:0 0 .5rem}
.tg-sec{margin:2rem 0 1rem}.tg-sec h3{font-size:1.35rem;font-weight:600;margin:0 0 1rem}
.tg-sec.col .tg-sum{font-size:1.35rem;font-weight:600;cursor:pointer;margin:0 0 .75rem;background:none;border:0;padding:0;font-family:inherit;color:inherit;display:flex;align-items:center;gap:.2rem}
.tg-sec.col .tg-sum::before{content:"›";display:inline-block;width:1.2rem;color:var(--tg-muted);transition:transform .2s}.tg-sec.col.is-open .tg-sum::before{transform:rotate(90deg)}
.tg-sec.col .tg-panel{display:none}
.tg-grid{display:grid;grid-template-columns:200px minmax(0,1fr);gap:1.25rem 1rem;align-items:center;margin:0 0 .5rem}
.tg-grid label.l{color:var(--tg-ink)}
.tg-in{width:100%;max-width:420px;padding:.55rem .7rem;border:1px solid #c9ced4;border-radius:3px;font:inherit;background:#fff}.tg-in:focus{outline:2px solid #bcd6f8;border-color:var(--tg-blue)}
.tg-in.sm{max-width:200px}.tg-in.md{max-width:300px}
.tg-inline{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap}.tg-inline .tg-in{flex:1 1 220px}
.tg-pw{position:relative;max-width:420px;flex:1 1 220px}.tg-pw .tg-in{max-width:none;padding-right:2.4rem}.tg-eye{position:absolute;right:.4rem;top:50%;transform:translateY(-50%);border:0;background:none;cursor:pointer;color:var(--tg-muted);padding:.2rem;display:flex}
.tg-pre{color:var(--tg-muted);white-space:nowrap}
.tg-opts{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:.5rem 1rem;max-width:720px}.tg-opts label{display:flex;gap:.45rem;align-items:baseline}
.tg-opts small{color:var(--tg-muted)}
.tg-note{font-size:.9rem;color:var(--tg-muted);margin:0 0 1rem}
.tg-packs{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:.75rem 1.25rem;margin:0 0 .5rem}.tg-pack{display:flex;gap:.6rem;align-items:flex-start;padding:.75rem .9rem;border:1px solid var(--tg-line);border-radius:4px;background:#fafbfc;line-height:1.4}.tg-pack input{margin-top:.3rem}.tg-pack:has(input:checked){border-color:var(--tg-blue);background:#f3f8ff}
.tg-busy{position:fixed;inset:0;background:rgba(255,255,255,.85);z-index:1060;display:none;align-items:center;justify-content:center;flex-direction:column;gap:.75rem;font-size:1.1rem}
.tg.busy .tg-busy{display:flex}.tg-spin{width:40px;height:40px;border:4px solid #dfe3e8;border-top-color:var(--tg-blue);border-radius:50%;animation:tgspin 1s linear infinite}@keyframes tgspin{to{transform:rotate(360deg)}}
@media (max-width:640px){.tg-grid{grid-template-columns:1fr;gap:.35rem}.tg-fly-head,.tg-fly-body,.tg-fly-foot{padding-left:1.1rem;padding-right:1.1rem}}
</style>

<div class="tg<?= $openFlyout ? ' open' : '' ?>" id="tg">
  <?php if ($cfg['branding'] !== ''): ?><p class="tg-muted" style="margin:0 0 .5rem"><?= $e($cfg['branding']) ?></p><?php endif; ?>
  <div class="tg-toolbar">
    <button type="button" class="tg-btn primary" data-tg-open <?= $phpNew ? '' : 'disabled' ?>>Install</button>
    <a class="tg-btn" href="?rescan=1">Rescan</a>
    <a class="tg-help" href="https://tiger.webtigers.com/docs" target="_blank" rel="noopener">ⓘ Help</a>
  </div>

  <?php if ($problems && !$openFlyout): ?><div class="tg-card bad"><strong>Please fix:</strong><ul><?php foreach ($problems as $p): ?><li><?= $e($p) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

  <?php if ($result): ?>
    <?php if (!empty($result['ok']) && empty($result['already_installed'])): ?>
      <div class="tg-card ok"><h3>Tiger is installed</h3>
        <p>Sign in at <a href="<?= $e($result['admin_url']) ?>" target="_blank"><?= $e($result['admin_url']) ?></a> as <strong><?= $e($result['login']['email'] ?? '') ?></strong><?php if (!empty($form['password_generated'])): ?> — generated password (shown once, copy it now): <code><?= $e($form['password']) ?></code><?php else: ?> with the password you chose.<?php endif; ?></p>
        <?php if (!empty($result['reused_db'])): ?><p class="tg-muted">Reused the database from your earlier attempt (<code><?= $e($result['reused_db']) ?></code>).</p><?php endif; ?>
        <?php if (!empty($result['skills']['installed'])): ?><p class="tg-muted"><?= count($result['skills']['installed']) ?> agent skills installed<?= !empty($result['skills']['failed']) ? '; could not fetch: ' . $e(implode(', ', $result['skills']['failed'])) : '' ?>.</p>
        <?php if (!empty($result['skills']['sources'])): ?><details><summary class="tg-steps-sum tg-muted">Skill sources</summary><ul class="tg-steps"><?php foreach ($result['skills']['sources'] as $src): ?><li><code><?= $e($src['name']) ?></code> <span class="tg-muted">github.com/<?= $e($src['repo']) ?>/<?= $e($src['path']) ?> @ <?= $e($src['commit'] ? substr($src['commit'], 0, 12) : $src['ref'] . ' (branch; commit not resolved)') ?></span></li><?php endforeach; ?></ul></details><?php endif; ?><?php endif; ?>
        <?php if (!empty($result['agent']['token'])): ?><p><strong>AI agent credential</strong> (shown once — copy it now): <code><?= $e($result['agent']['token']) ?></code><br>Endpoint: <code><?= $e($result['agent']['endpoint']) ?></code></p><?php endif; ?>
        <details><summary class="tg-steps-sum tg-muted">Steps (<?= count((array) ($result['steps'] ?? [])) ?>)</summary><ul class="tg-steps"><?php foreach ((array) ($result['steps'] ?? []) as $s): ?><li><code><?= $e($s['step']) ?></code> <span class="tg-ok"><?= $e($s['status']) ?></span> <span class="tg-muted"><?= $e($s['detail']) ?></span></li><?php endforeach; ?></ul></details>
      </div>
    <?php elseif (!empty($result['ok'])): ?>
      <div class="tg-card ok"><h3>Tiger was already installed there</h3><p>Version <?= $e($result['version']) ?>. Sign in at <a href="<?= $e($result['admin_url']) ?>" target="_blank"><?= $e($result['admin_url']) ?></a>.</p></div>
    <?php else: ?>
      <div class="tg-card bad"><h3>The install stopped at “<?= $e($result['error']['step'] ?? '?') ?>”</h3><p><?= $e($result['error']['message'] ?? '') ?></p>
        <p class="tg-muted">Fix what it names and run Install again with the same domain — it resumes from that step with the same database<?= !empty($result['reused_db']) ? ' (<code>' . $e($result['reused_db']) . '</code>)' : '' ?>. Nothing is web-reachable until every step passes.</p>
        <details><summary class="tg-steps-sum tg-muted">Steps (<?= count((array) ($result['steps'] ?? [])) ?>)</summary><ul class="tg-steps"><?php foreach ((array) ($result['steps'] ?? []) as $s): ?><li><code><?= $e($s['step']) ?></code> <span class="<?= $s['status'] === 'failed' ? 'tg-bad' : 'tg-ok' ?>"><?= $e($s['status']) ?></span> <span class="tg-muted"><?= $e($s['detail']) ?></span></li><?php endforeach; ?></ul></details>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php
  // The intro: what Tiger is, for someone who opened this because it was in the menu. The copy comes from
  // the catalog (WebTigers/TigerCatalog → intro), so marketing edits one file and every host's page follows.
  // A returning user came for the list, so the list stays on top; with nothing installed the intro IS the page.
  $intro = static function ($which) use ($mine, $phpNew, $e, $cfg, $catalog) {
      $in = $catalog['intro'] ?? null;
      if ($in === null) { return; }
      if ($which === 'hero'): $h = $in['hero']; ?>
    <section class="tg-hero<?= empty($mine['installs']) ? ' big' : '' ?>">
      <svg class="tg-paw" viewBox="0 0 24 24" aria-hidden="true"><g fill="#2f7be0"><ellipse cx="6.2" cy="9.2" rx="2.1" ry="2.7"/><ellipse cx="17.8" cy="9.2" rx="2.1" ry="2.7"/><ellipse cx="9.4" cy="5.2" rx="2.1" ry="2.8"/><ellipse cx="14.6" cy="5.2" rx="2.1" ry="2.8"/><path d="M12 10.2c-3.6 0-6.6 3.1-6.6 6.2 0 2 1.4 3.4 3.3 3.4 1.1 0 1.9-.5 3.3-.5s2.2.5 3.3.5c1.9 0 3.3-1.4 3.3-3.4 0-3.1-3-6.2-6.6-6.2z"/></g></svg>
      <div>
        <h2><?= $e($h['title']) ?></h2>
        <p><?= $e($h['text']) ?></p>
        <div class="tg-cta">
          <button type="button" class="tg-btn primary" data-tg-open <?= $phpNew ? '' : 'disabled' ?>>Install Tiger</button>
          <a class="tg-link" href="<?= $e($h['link_url']) ?>" target="_blank" rel="noopener"><?= $e($h['link_label']) ?> →</a>
        </div>
        <?php if (!$phpNew): ?><p class="tg-bad" style="margin:1rem 0 0">This server has no PHP <?= $e($cfg['min_php']) ?> or newer available — ask your host.</p><?php endif; ?>
      </div>
    </section>
      <?php elseif ($which === 'cards' && $in['cards']): ?>
    <div class="tg-cards">
      <?php foreach ($in['cards'] as $c): ?>
      <div><p class="tg-kicker"><?= $e(strtoupper($c['kicker'])) ?></p><h3><?= $e($c['title']) ?></h3><p><?= $e($c['text']) ?></p><a href="<?= $e($c['link_url']) ?>" target="_blank" rel="noopener"><?= $e($c['link_label']) ?> →</a></div>
      <?php endforeach; ?>
    </div>
      <?php endif;
  };
  ?>

  <?php if (empty($mine['installs'])): ?>
    <?php $intro('hero'); $intro('cards'); ?>
  <?php else: ?>
    <h2 class="tg-sites-h">Your Tiger sites</h2>
    <table class="tg-table"><thead><tr><th>Site</th><th>Version</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($mine['installs'] as $i): $dom = $domainFor($i); $url = $dom ? 'https://' . $dom : ''; ?>
      <tr>
        <td class="tg-site"><?php if ($url): ?><a href="<?= $e($url) ?>" target="_blank" rel="noopener"><?= $e($dom) ?></a><?php else: ?><?= $e(basename(dirname((string) $i['app_root']))) ?><?php endif; ?><small><?= $e($i['app_root']) ?></small></td>
        <td><?= $e($i['version']) ?><?php if (!empty($i['update_available'])): ?> <span class="tg-pill warn" title="Your host updates from WHM">→ <?= $e($i['latest']) ?> available</span><?php endif; ?></td>
        <td><?= $i['installed'] === true ? '<span class="tg-pill ok">Live</span>' : ($i['installed'] === null ? '<span class="tg-pill mute">Unknown</span>' : '<span class="tg-pill bad">Not finished</span>') ?></td>
        <td style="text-align:right;white-space:nowrap"><?php if ($dom && $i['installed'] === true): ?>
          <form method="post" target="_blank" style="display:inline"><input type="hidden" name="action" value="login"><input type="hidden" name="nonce" value="<?= $e($nonce) ?>"><input type="hidden" name="app_root" value="<?= $e($i['app_root']) ?>"><input type="hidden" name="host" value="<?= $e($dom) ?>"><button type="submit" class="tg-btn" title="Sign in to this site's admin as its owner">Admin</button></form>
        <?php elseif ($url): ?><a class="tg-btn" href="<?= $e($url) ?>/admin" target="_blank" rel="noopener">Admin</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php $intro('hero'); $intro('cards'); ?>
  <?php endif; ?>

  <!-- Install flyout -->
  <div class="tg-scrim" data-tg-close></div>
  <form class="tg-fly" method="post" autocomplete="off" id="tg-form" aria-label="Install Tiger">
    <input type="hidden" name="action" value="install"><input type="hidden" name="nonce" value="<?= $e($nonce) ?>"><input type="hidden" name="password_generated" id="tg-pwgen" value="0">
    <div class="tg-fly-head"><h2>Install Tiger</h2><p>Choose installation options</p><button type="button" class="tg-x" data-tg-close aria-label="Close">×</button></div>
    <div class="tg-fly-body">
      <?php if ($problems): ?><div class="tg-card bad" style="margin-bottom:1rem"><strong>Please fix:</strong><ul><?php foreach ($problems as $p): ?><li><?= $e($p) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <p class="tg-hint">Random values will be generated if the password and database fields are left blank.</p>

      <div class="tg-sec"><h3>General</h3></div>
      <div class="tg-grid">
        <label class="l" for="tg-domain">Install on</label>
        <select class="tg-in" name="domain" id="tg-domain">
          <option value="">— choose a domain —</option>
          <?php foreach ($domains as $d): $has = isset($byDocroot[rtrim($d['docroot'], '/')]); ?>
            <option value="<?= $e($d['domain']) ?>" <?= (!$d['php_ok'] || $has) ? 'disabled' : '' ?> <?= ($form['domain'] ?? '') === $d['domain'] ? 'selected' : '' ?>><?= $e($d['domain']) ?><?= $has ? ' — Tiger already here' : (!$d['php_ok'] ? ' — PHP too old (' . $e($d['php']) . ')' : '') ?></option>
          <?php endforeach; ?>
        </select>
        <label class="l" for="tg-sub">…or a new subdomain</label>
        <div class="tg-inline"><input class="tg-in sm" id="tg-sub" name="new_sub" placeholder="app" value="<?= $e($form['new_sub'] ?? '') ?>"><span class="tg-pre">.<?= $e($mainDomain) ?></span></div>
        <label class="l" for="tg-site">Website title</label>
        <input class="tg-in" id="tg-site" name="site_name" placeholder="My Site" value="<?= $e($form['site_name'] ?? '') ?>">
        <label class="l" for="tg-theme">Theme</label>
        <select class="tg-in" id="tg-theme" name="theme">
          <option value="">Tiger default</option>
          <?php foreach ($feed['themes'] as $t): ?><option value="<?= $e($t['slug']) ?>" <?= ($form['theme'] ?? $pre['theme']) === $t['slug'] ? 'selected' : '' ?>><?= $e($t['name']) ?> <?= $e($t['version']) ?></option><?php endforeach; ?>
        </select>
        <label class="l">Modules</label>
        <div class="tg-opts">
          <?php if ($feed['available']): foreach ($feed['modules'] as $m): $on = in_array($m['slug'], isset($form['modules']) ? $form['modules'] : $pre['modules'], true); ?>
            <label title="<?= $e($m['description']) ?>"><input type="checkbox" name="modules[]" value="<?= $e($m['slug']) ?>" <?= $on ? 'checked' : '' ?>> <span><?= $e($m['name']) ?> <small><?= $e($m['version']) ?></small></span></label>
          <?php endforeach; else: ?><span class="tg-muted">The module directory isn't reachable right now — add modules later from Tiger's Module Manager.</span><?php endif; ?>
        </div>
        <label class="l" for="tg-locale">Website language</label>
        <select class="tg-in md" id="tg-locale" name="locale"><?php foreach (['en' => 'English', 'es' => 'Español', 'pt' => 'Português', 'de' => 'Deutsch', 'fr' => 'Français', 'hi' => 'हिन्दी'] as $k => $v): ?><option value="<?= $k ?>" <?= $k === ($form['locale'] ?? $cfg['locale']) ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
        <label class="l">Protocol</label>
        <label><input type="checkbox" name="https" value="1" <?= ($form['https'] ?? true) ? 'checked' : '' ?>> Use HTTPS <span class="tg-muted">(run AutoSSL first if the domain has no certificate)</span></label>
      </div>

      <?php if ($catalog['skill_packs']): ?>
      <div class="tg-sec"><h3>Skills</h3></div>
      <p class="tg-note">Know-how for Tiger's AI agent, installed as sets. Add or remove any later from Settings → Agent → Skills.</p>
      <div class="tg-packs">
        <?php foreach ($catalog['skill_packs'] as $pk): $on = in_array($pk['id'], isset($form['packs']) ? $form['packs'] : $pre['skill_packs'], true); ?>
          <label class="tg-pack"><input type="checkbox" name="packs[]" value="<?= $e($pk['id']) ?>" <?= $on ? 'checked' : '' ?>>
            <span><strong><?= $e($pk['name']) ?></strong> <small class="tg-muted"><?= count($pk['skills']) ?> skills</small><br><span class="tg-muted"><?= $e($pk['description']) ?></span></span></label>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="tg-sec"><h3>Tiger Administrator</h3></div>
      <div class="tg-grid">
        <label class="l" for="tg-user">Username <span class="tg-muted">(optional)</span></label>
        <input class="tg-in" id="tg-user" name="username" value="<?= $e($form['username'] ?? '') ?>">
        <label class="l" for="tg-pass">Password</label>
        <div class="tg-inline"><span class="tg-pw"><input class="tg-in" id="tg-pass" name="password" type="password" minlength="12"><button type="button" class="tg-eye" data-tg-eye="tg-pass" aria-label="Show"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg></button></span><button type="button" class="tg-btn" data-tg-gen="tg-pass">Generate</button></div>
        <label class="l" for="tg-email">Email</label>
        <input class="tg-in" id="tg-email" name="email" type="email" required value="<?= $e($form['email'] ?? '') ?>">
      </div>

      <div class="tg-sec col"><button type="button" class="tg-sum" data-tg-toggle aria-expanded="false">Database</button><div class="tg-panel">
        <p class="tg-note">Created for you through cPanel. Change the names only if you need to.</p>
        <div class="tg-grid">
          <label class="l" for="tg-dbn">Database name</label>
          <div class="tg-inline"><span class="tg-pre"><?= $e($prefix) ?></span><input class="tg-in md" id="tg-dbn" name="db_name" placeholder="tg&lt;domain&gt;" value="<?= $e($form['db_name'] ?? '') ?>"></div>
          <label class="l" for="tg-dbu">Database user name</label>
          <div class="tg-inline"><span class="tg-pre"><?= $e($prefix) ?></span><input class="tg-in md" id="tg-dbu" name="db_user" placeholder="tg&lt;domain&gt;" value="<?= $e($form['db_user'] ?? '') ?>"></div>
          <label class="l" for="tg-dbp">Database user password</label>
          <div class="tg-inline"><span class="tg-pw"><input class="tg-in" id="tg-dbp" name="db_password" type="password" placeholder="generated"><button type="button" class="tg-eye" data-tg-eye="tg-dbp" aria-label="Show"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg></button></span><button type="button" class="tg-btn" data-tg-gen="tg-dbp">Generate</button></div>
        </div>
      </div></div>

      <?php if (!empty($cfg['allow_agent'])): ?>
      <div class="tg-sec col"><button type="button" class="tg-sum" data-tg-toggle aria-expanded="false">AI agent</button><div class="tg-panel">
        <div class="tg-grid">
          <label class="l">Connect an agent</label>
          <label><input type="checkbox" name="agent" value="1" <?= !empty($form['agent']) ? 'checked' : '' ?>> Mint a credential for Tiger's MCP endpoint at install <span class="tg-muted">(shown once on the result)</span></label>
        </div>
      </div></div>
      <?php endif; ?>

      <p class="tg-note" style="margin-top:1.25rem">Tiger installs <em>above</em> the document root — your configuration and secrets are never web-reachable. Your existing sites are not touched.</p>
    </div>
    <div class="tg-fly-foot">
      <button type="submit" class="tg-btn primary" id="tg-submit">Install</button>
      <button type="button" class="tg-btn" data-tg-close>Cancel</button>
    </div>
  </form>

  <div class="tg-busy"><div class="tg-spin"></div><div>Installing Tiger… this takes about a minute.</div><div class="tg-muted" style="font-size:.9rem">Creating the database, downloading and verifying the release, building the schema.</div></div>
</div>

<script>
(function () {
  // expand/collapse ported from Tiger's tiger.dom.js (Web Animations API, interruptible, reduced-motion aware).
  var REDUCE = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var D = { expand: 240, fade: 140, easing: 'cubic-bezier(.4, 0, .2, 1)' };
  function cancel(el) { if (el.__a) { try { el.__a.cancel(); } catch (e) {} el.__a = null; } }
  function run(el, frames, ms) { var a = el.animate(frames, { duration: ms, easing: D.easing }); el.__a = a; return a.finished; }
  function expand(el) {
    var gen = (el.__g = (el.__g || 0) + 1); cancel(el); el.__open = true; el.style.display = 'block';
    if (REDUCE) { el.style.height = ''; el.style.overflow = ''; el.style.opacity = ''; return Promise.resolve(); }
    el.style.height = ''; var target = el.scrollHeight; el.style.overflow = 'hidden'; el.style.opacity = '0'; el.style.height = target + 'px';
    return run(el, [{ height: '0px' }, { height: target + 'px' }], D.expand).then(function () {
      if (el.__g !== gen) { return; } el.style.height = ''; el.style.overflow = ''; el.style.opacity = '1';
      return run(el, [{ opacity: 0 }, { opacity: 1 }], D.fade);
    }).then(function () { if (el.__g === gen) { el.style.opacity = ''; el.__a = null; } }).catch(function () {});
  }
  function collapse(el) {
    var gen = (el.__g = (el.__g || 0) + 1); cancel(el); el.__open = false;
    if (REDUCE) { el.style.display = 'none'; el.style.height = ''; el.style.overflow = ''; el.style.opacity = ''; return Promise.resolve(); }
    el.style.overflow = 'hidden'; el.style.opacity = '0';
    return run(el, [{ opacity: 1 }, { opacity: 0 }], D.fade).then(function () {
      if (el.__g !== gen) { return; } var start = el.scrollHeight; el.style.height = '0px';
      return run(el, [{ height: start + 'px' }, { height: '0px' }], D.expand);
    }).then(function () { if (el.__g !== gen) { return; } el.style.display = 'none'; el.style.height = ''; el.style.overflow = ''; el.style.opacity = ''; el.__a = null; }).catch(function () {});
  }

  var root = document.getElementById('tg');
  function open()  { root.classList.add('open'); setTimeout(function () { var f = document.getElementById('tg-domain'); if (f) { f.focus(); } }, 320); }
  function close() { root.classList.remove('open'); }
  root.querySelectorAll('[data-tg-open]').forEach(function (b) { b.addEventListener('click', open); });
  root.querySelectorAll('[data-tg-close]').forEach(function (b) { b.addEventListener('click', close); });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { close(); } });
  root.querySelectorAll('[data-tg-toggle]').forEach(function (b) {
    var sec = b.parentNode, panel = sec.querySelector('.tg-panel');
    b.addEventListener('click', function () {
      var on = !sec.classList.contains('is-open');
      sec.classList.toggle('is-open', on); b.setAttribute('aria-expanded', on ? 'true' : 'false');
      (on ? expand : collapse)(panel);
    });
  });
  // A section holding a value the server bounced (e.g. a bad database name) opens itself.
  root.querySelectorAll('.tg-sec.col').forEach(function (sec) {
    var dirty = Array.prototype.some.call(sec.querySelectorAll('input:not([type=hidden])'), function (i) { return i.type === 'checkbox' ? i.checked : i.value !== ''; });
    if (dirty) { sec.classList.add('is-open'); sec.querySelector('.tg-sum').setAttribute('aria-expanded', 'true'); sec.querySelector('.tg-panel').style.display = 'block'; }
  });
  function gen(n) { var a = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789', o = '', r = new Uint32Array(n); crypto.getRandomValues(r); for (var i = 0; i < n; i++) { o += a[r[i] % a.length]; } return o; }
  root.querySelectorAll('[data-tg-gen]').forEach(function (b) { b.addEventListener('click', function () { var i = document.getElementById(b.getAttribute('data-tg-gen')); i.value = gen(20); i.type = 'text'; }); });
  root.querySelectorAll('[data-tg-eye]').forEach(function (b) { b.addEventListener('click', function () { var i = document.getElementById(b.getAttribute('data-tg-eye')); i.type = i.type === 'password' ? 'text' : 'password'; }); });
  document.getElementById('tg-form').addEventListener('submit', function () {
    var p = document.getElementById('tg-pass'); if (p.value === '') { p.value = gen(20); document.getElementById('tg-pwgen').value = '1'; }   // blank = generated, as the hint says
    document.getElementById('tg-submit').disabled = true; root.classList.add('busy');
  });
})();
</script>
<?php
print $cpanel->footer();
$cpanel->end();
