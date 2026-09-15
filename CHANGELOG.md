# Changelog

All notable changes to **TigerWHM**. Format follows [Keep a Changelog](https://keepachangelog.com/); SemVer.

## [1.0.1] — 2026-09-15

Installer hardening from Sol AI's static review (TIGER-137: 132–136), each proven on host3 or by
tests that run the engine's real validators. Engine tiger-headless **0.6.2**.

### Fixed

- **Addon-domain app root** (TIGER-132): `<home>/<domain>/tiger-app` lands INSIDE the docroot when an
  addon domain's document root is `<home>/<domain>`. `TigerWHM_Account::appRootFor()` now picks
  `<home>/tiger-apps/<domain>` in that case; either way the result is outside the docroot. The
  complete spec is validated by the engine's own `Tiger_Headless_Spec` before anything is created.
- **Failed installs resume** (TIGER-133): provisioning was not idempotent — a retry died on "already
  exists" before the engine could resume its ledger. Now: a subdomain the account already has is
  reused, not re-created; the database + user created for an attempt are remembered per domain
  (`~/.tigerwhm/pending/<domain>.json`, 0600) and reused on retry until the install succeeds; a
  requirements refusal after provisioning rolls the database + user back. The failed form keeps the
  created subdomain as the chosen domain. Surfaced an engine bug on the way: a failure after step 7
  was adopted as "already installed" (engine 0.6.2 fixes it).
- **PHP fails closed** (TIGER-134): no fallback to the newest CLI PHP. An install or update runs
  under the vhost's OWN assigned PHP or not at all; below the minimum → refused, naming MultiPHP
  Manager. The WHM fleet no longer offers such a site for update, and a direct POST is refused too.
- **Panel renders never wait on GitHub** (TIGER-135): catalog + Directory fetches get a 5-second
  budget each (connect ≥ 3 s) — a blackholed GitHub falls to the cached copy or bundled snapshot in
  ≤ 10 s instead of ~4 minutes. Release downloads keep the engine's 120 s.
- The WHM "Update selected" button no longer uses a browser `confirm()`.

### Added

- **Catalog trust mode** (TIGER-136): host defaults gain `catalog.mode` — `live` (default: read
  `main` hourly) or `pinned` (read the catalog and the Directory at two named commits and nothing
  else, until the host moves the pins). The WHM page states the effective mode and source.
- **Install record**: every completed install appends a line to `~/.tigerwhm/installs.log` — core
  version, theme, modules, each skill with the commit it was installed at, and the catalog/Directory
  sources — and the result card lists the skill sources. Skills now install at their resolved
  commit (engine 0.6.1), so a moving branch cannot change what a site got.

## [1.0.0] — 2026-09-15

First stable release. The plugin every cPanel account gets: one-click Tiger installs, the fleet from WHM,
and a page whose offer and copy come live from WebTigers/TigerCatalog.

### Added

- **The page explains what Tiger is — and the copy comes from the catalog.** A hero ("Build a website by
  talking to AI.") and discovery cards (build a website · sell something · build an app), read from
  `WebTigers/TigerCatalog` `intro` like the skill packs are: marketing edits one file, every host's page
  follows, no plugin update. Plain text + https links only; off-shape copy is dropped, never rendered.
  With sites installed the list stays on top; with none, the intro is the page (replaces the empty state).

### Changed

- The menu item and page are **"Tiger AI Site Management"** (cPanel left menu, page title, WHM plugin label),
  and it is the **first item under Tools** (`order: 5`, above Sitejet / WordPress Management).

### Fixed

- The result card's Steps disclosure shows a chevron and a count instead of a bare heading.

## [0.2.0] — 2026-09-14

The WP Toolkit shape, sign-in from the list, skill packs, and a live catalog. Engine v0.6.0.


### Added

- **Skills, as packs.** A Skills section in the flyout offers *groups* of Agent Skills ("Web design",
  "Content & publishing", "Development", "Documents & files") installed as a set — nobody picks
  nineteen skills one by one. Installed + activated at install through Tiger's own skills store
  (engine 0.6.0).
- **Everything offered comes live from a public repo.** Themes and modules from the Directory feed
  (as before); what is featured and the skill packs from
  its own public repo, `WebTigers/TigerCatalog` (`TigerWHM_Catalog`, cached hourly, bundled snapshot
  as the last resort). Add, remove or re-group and every new install on every host sees it — no plugin
  update. Host defaults default to "follow the catalog" per row, with an override.
- **Admin signs you in.** The list's Admin button mints a one-time, 2-minute sign-in link for the
  site's founding admin (engine `login`, tiger-core ≥ 1.8.0 magic-link login) and sends the browser
  straight into `/admin` — WP Toolkit's "Log in". Single-use, audited on the site; a replay lands on
  the normal sign-in page. Sites on an older core keep a plain Admin link until the host updates them.

### Changed

- **The flyout flies** (slide from the right + scrim fade, reduced-motion aware) and the collapsible
  sections animate open/closed with expand/collapse ported from Tiger's `tiger.dom.js`.
- **The account page follows the WP Toolkit shape:** toolbar (Install / Rescan / Help), the list of
  installs as the page (or an empty state), and Install as a slide-over flyout with grouped sections
  (General · Tiger Administrator · Database · AI agent), a label/field grid, Generate + show/hide on
  passwords, "random values are generated if left blank" (a generated admin password is shown once on
  the result), an editable Database section (name/user/password inside cPanel's prefix rules), and
  Install/Cancel pinned to the bottom. `?open=1` deep-links to the flyout.
- The account-side entry is a **top-level cPanel left-menu item, "Tiger Management"** (`menu/LeftMenu.yaml`, order 5 — first under Tools), not an icon in the Software grid. WHM label matches.

## [0.1.0] — 2026-09-14

First release (TIGER-39). A front-end over tiger-headless (vendored, v0.4.0).

### Added

- **cPanel → left menu → Tiger Management** (a top-level nav item via `menu/LeftMenu.yaml`; Jupiter, LivePHP, runs as the account): the account's Tigers
  (whoever installed them) with version, live state, update note and Admin link; one-click install on a
  domain, addon domain or a new subdomain — database + user through UAPI, then the engine under the
  domain's own PHP; theme + modules from the Directory feed, pre-ticked to the host's defaults; optional
  AI-agent credential. The result names the admin URL, or the step that stopped and why.
- **WHM → Plugins → Tiger Management** (root): the fleet (every Tiger under `/home` joined to WHM's account/vhost
  table — account, domain, app root, PHP, version, live state, update available) with per-install and
  select-all updates, each run as the account user under its vhost's PHP; host defaults
  (`/etc/tigerwhm/config.json`); the below-minimum-PHP gate.
- `install.sh` (idempotent, verifies the release tarball's sha256; registers with `install_plugin` and
  `register_appconfig`), `uninstall.sh` (leaves sites, databases and defaults alone), `build.sh`.
- Unit suite with a fake UAPI and a fake engine subprocess.

### Proven on a live server

host3 (cPanel 138, 39 accounts): fleet view in 2.4 s; one-click install of `app2.authors.host` on an
account with `symlink()` disabled and MySQL 8, beside an existing web-installed Tiger and inside the
account's `public_html` — 12 s to a live site. It surfaced two bugs fixed in tiger-core 1.7.1 /
tiger-headless 0.4.0 (cPanel's pre-written `.htaccess`, theme assets on a split docroot).
