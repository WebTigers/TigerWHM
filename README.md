# TigerWHM

**A free WHM/cPanel plugin that lets every cPanel account on a server install
[Tiger](https://github.com/WebTigers/Tiger) in one click** — and gives the host a fleet view and fleet
updates. No Softaculous licence required. BSD-3-Clause.

```bash
# on the WHM server, as root — install or update:
bash <(curl -fsSL https://raw.githubusercontent.com/WebTigers/TigerWHM/main/install.sh)
```

## What the account holder gets — cPanel left menu → **Tiger Management**

- **Your Tigers** — every Tiger in the account, whoever installed it (this plugin, the web installer,
  Composer), with version, live state and an Admin link.
- **Install Tiger** — pick a domain or addon domain, or type a new subdomain; admin email + password;
  a theme and modules pre-ticked to the host's defaults (the list comes from the
  [Directory](https://github.com/WebTigers/TigerVendors) feed, so a new free module appears without a
  plugin release). One click: the database and user are created through cPanel's own API, then the
  install runs — **above the document root**, under the domain's own PHP. The page reports the admin
  URL, or exactly which step stopped and why (submit again and it resumes from that step).
- **Skills** — packs of Agent Skills (Web design, Content & publishing, Development, Documents & files)
  installed as a set; add or remove any later from Tiger's own Skills screen.
- **The lists are live.** Themes and modules come from the [Directory](https://github.com/WebTigers/TigerVendors)
  feed; what's featured and the skill packs from
  [`install/catalog.json`](https://github.com/WebTigers/TigerVendors/blob/main/install/catalog.json).
  Change either on `main` and every new install everywhere sees it — no host updates anything.
- Installing beside an existing site is the ordinary case (a subdomain of a WordPress account, say);
  the existing site and its `.htaccess` are never touched.

## What the host gets — WHM → Plugins → **Tiger Management**

- **Fleet** — every Tiger under `/home`, joined to WHM's account and vhost table: account, domain,
  app root, PHP, version, live state, and whether a newer tiger-core exists (`3 of 12 need an update`).
- **Update selected / all** — each install updated **as its own account user, under its vhost's PHP**,
  with Tiger's own backup-and-swap updater. Results per install.
- **Host defaults** — pre-selected theme and modules, default language, a branding line on the
  account page, minimum PHP, an (unauthenticated) mail relay, whether accounts may mint an AI-agent
  credential. Stored in `/etc/tigerwhm/config.json` — world-readable by design, so it holds no secrets.
- **Requirements gate** — accounts whose PHP is below the minimum, so the host can fix them in
  MultiPHP Manager before anyone hits the wall.

## How it is built — no install logic in the plugin

The plugin is a front-end over [tiger-headless](https://github.com/WebTigers/TigerHeadless), the
non-interactive install authority, vendored under `/opt/tigerwhm/engine`. Every verb — install,
check, discover, upgrade — is a subprocess of that engine and every answer is its JSON. If the
headless installer changes, the plugin changes by bumping `ENGINE_VERSION`.

```
/opt/tigerwhm/                       lib/ (this plugin)  engine/ (tiger-headless)  cpanel/  whm/  appconfig/
/etc/tigerwhm/config.json            host defaults
/usr/local/cpanel/base/frontend/jupiter/tiger/index.live.php   the cPanel page (LivePHP, runs as the account)
/usr/local/cpanel/whostmgr/docroot/cgi/tiger/index.cgi         the WHM page (runs as root)
```

`uninstall.sh` removes the plugin and leaves every installed site, database and your defaults alone.

## Requirements

cPanel & WHM with the Jupiter theme; EasyApache PHP 8.1+ available for the accounts that will install
(`ea-php81` or newer — the WHM page lists any account below it). MariaDB or MySQL 8. `symlink()` may be
disabled for accounts; assets are copied then.

## Development

```
composer install && vendor/bin/phpunit          # the lib, with a fake UAPI + fake engine
./build.sh                                      # dist/tigerwhm-<version>.tar.gz with the engine vendored
sudo bash install.sh --from $(pwd)              # on a WHM box: install the working tree
```

## License

BSD-3-Clause. Tiger™ and WebTigers™ are trademarks of WebTigers. cPanel® and WHM® are trademarks of
WebPros International, LLC; this plugin is not affiliated with or endorsed by cPanel.
