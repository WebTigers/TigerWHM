# AGENTS.md — working in TigerWHM

Read [tiger-core's AGENTS.md](https://github.com/WebTigers/TigerCore/blob/main/AGENTS.md) first for the
platform conventions, and [TigerHeadless's](https://github.com/WebTigers/TigerHeadless/blob/main/AGENTS.md)
for the engine this plugin drives.

## The one rule

**No install logic in the plugin.** Every install, check, discovery and upgrade is a subprocess of the
vendored `tiger-headless` engine (`TigerWHM_Engine`), and every answer the pages show is the engine's
JSON. If the plugin needs something the engine cannot do, add it to the engine (with its tests) and bump
`ENGINE_VERSION` here. `build.sh` vendors a **tagged** engine release — never a working copy.

## Shape

- `lib/TigerWHM/*` — testable, HTML-free: `Config` (host defaults, world-readable, no secrets),
  `Directory` (the feed → checkbox lists), `Engine` (subprocess under the right PHP, as the right user),
  `Cpanel` (the one UAPI seam; `fake()` for tests), `Account` (the account holder's flow),
  `Fleet` (the host's flow over whmapi1), `Http` (CGI/LivePHP request, nonce, escaping).
- `cpanel/tiger/index.live.php` — the account page. Runs under cPanel's LivePHP **as the account user**.
- `whm/index.cgi` — the WHM page. php-cli with a shebang, **as root**; we emit the CGI header ourselves.
- `install.sh` / `uninstall.sh` — what root runs. Idempotent. Exactly the paths listed in README.md.

## Things learned on a real server (keep them true)

- cPanel writes a `.htaccess` into a new subdomain's docroot before any install (the PHP handler
  block). The engine **merges** Tiger's rules under a marker; never copy over it, never skip it.
- The account's `ea-php` may disable `proc_open`/`exec`/`symlink`. The **pages** run under cPanel's own
  PHP (unrestricted) and spawn the engine under the account's PHP; the engine itself never shells out.
- The WHM page updates each install with `su -s /bin/sh <user> -c …` — a cPanel account's shell is
  often `noshell`.
- The **left-menu item** is `menu/LeftMenu.yaml` (key = the install.json `id`, `order`, an inline white SVG). cPanel's MenuBuilder scans `/var/cpanel/plugins/*/menu/` — `install_plugin` does NOT copy us there, so `install.sh` places `/var/cpanel/plugins/tigerwhm/` itself BEFORE running `install_plugin` (which rebuilds `/var/cpanel/menus/LeftMenu.yaml` when the tarball has `menu/`). Nobody finds a Software-grid icon; the menu item is the product.
- Icons: WHM's go to WHM's to
  `whostmgr/docroot/addon_plugins/`. `install_plugin` registers only the link/feature — the page files
  are ours to place.

## Tests

`composer install && vendor/bin/phpunit` — everything is faked (UAPI, whmapi1, the engine subprocess).
The real proof is a WHM box: `sudo bash install.sh --from <built tree>`, then the two pages.
