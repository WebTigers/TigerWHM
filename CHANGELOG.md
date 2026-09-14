# Changelog

All notable changes to **TigerWHM**. Format follows [Keep a Changelog](https://keepachangelog.com/); SemVer.

## [Unreleased]

### Added

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
- The account-side entry is a **top-level cPanel left-menu item, "Tiger Management"** (`menu/LeftMenu.yaml`, order 15), not an icon in the Software grid. WHM label matches.

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
