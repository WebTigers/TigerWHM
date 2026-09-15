#!/bin/bash
# SPDX-License-Identifier: BSD-3-Clause
# Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
#
# TigerWHM — install or update the plugin on a WHM/cPanel server. Run as root:
#
#   bash <(curl -fsSL https://raw.githubusercontent.com/WebTigers/TigerWHM/main/install.sh)
#   bash install.sh --version v1.0.3          # a specific release
#   bash install.sh --from /path/to/checkout  # a local tree (development)
#
# Idempotent: re-running updates in place. What it does, and nothing else:
#   /opt/tigerwhm/                       the plugin lib + the vendored tiger-headless engine + scripts
#   /etc/tigerwhm/config.json            host defaults (created with the shipped defaults if absent)
#   /usr/local/cpanel/base/frontend/jupiter/tiger/   the cPanel page (+ install_plugin registration)
#   /usr/local/cpanel/whostmgr/docroot/cgi/tiger/    the WHM page (+ register_appconfig)
set -euo pipefail

REPO="WebTigers/TigerWHM"
HOME_DIR="/opt/tigerwhm"
VERSION=""
FROM=""
while [ $# -gt 0 ]; do
  case "$1" in
    --version) VERSION="$2"; shift 2;;
    --from)    FROM="$2"; shift 2;;
    *) echo "unknown option: $1" >&2; exit 2;;
  esac
done

[ "$(id -u)" = "0" ] || { echo "run as root" >&2; exit 1; }
[ -x /usr/local/cpanel/scripts/install_plugin ] || { echo "this is not a cPanel/WHM server (no /usr/local/cpanel/scripts/install_plugin)" >&2; exit 1; }
command -v curl >/dev/null || { echo "curl is required" >&2; exit 1; }

work="$(mktemp -d /tmp/tigerwhm.XXXXXX)"
trap 'rm -rf "$work"' EXIT

# ---- 1. get the release tree into $work/src ------------------------------------------------------
if [ -n "$FROM" ]; then
  [ -f "$FROM/VERSION" ] && [ -d "$FROM/engine" ] || { echo "--from must point at a built tree (VERSION + engine/): run build.sh first" >&2; exit 1; }
  mkdir -p "$work/src" && cp -a "$FROM"/. "$work/src/"
else
  api="https://api.github.com/repos/$REPO/releases/${VERSION:+tags/}${VERSION:-latest}"
  url="$(curl -fsSL -A tigerwhm-install "$api" | python3 -c 'import sys,json
r=json.load(sys.stdin)
for a in r.get("assets",[]):
    if a["name"].startswith("tigerwhm-") and a["name"].endswith(".tar.gz"): print(a["browser_download_url"]); break' 2>/dev/null || true)"
  if [ -z "$url" ]; then
    # python3 is not guaranteed on every box; fall back to a grep.
    url="$(curl -fsSL -A tigerwhm-install "$api" | grep -oE '"browser_download_url": *"[^"]*tigerwhm-[^"]*\.tar\.gz"' | head -1 | sed -E 's/.*"(https[^"]*)"/\1/')"
  fi
  [ -n "$url" ] || { echo "no tigerwhm-*.tar.gz on release ${VERSION:-latest}" >&2; exit 1; }
  echo "Downloading $url"
  curl -fsSL -A tigerwhm-install -o "$work/tigerwhm.tar.gz" "$url"
  curl -fsSL -A tigerwhm-install -o "$work/tigerwhm.tar.gz.sha256" "$url.sha256"
  want="$(awk '{print tolower($1)}' "$work/tigerwhm.tar.gz.sha256")"
  have="$(sha256sum "$work/tigerwhm.tar.gz" | awk '{print $1}')"
  [ -n "$want" ] && [ "$want" = "$have" ] || { echo "checksum mismatch — refusing to install" >&2; exit 1; }
  mkdir -p "$work/src" && tar -xzf "$work/tigerwhm.tar.gz" -C "$work/src" --strip-components=1
fi
ver="$(cat "$work/src/VERSION")"
echo "TigerWHM $ver"

# ---- 2. /opt/tigerwhm ---------------------------------------------------------------------------
mkdir -p "$HOME_DIR"
for d in lib engine cpanel whm appconfig; do rm -rf "$HOME_DIR/$d"; cp -a "$work/src/$d" "$HOME_DIR/$d"; done
cp "$work/src/VERSION" "$work/src/install.sh" "$work/src/uninstall.sh" "$HOME_DIR/"
chown -R root:root "$HOME_DIR"
find "$HOME_DIR" -type d -exec chmod 755 {} + ; find "$HOME_DIR" -type f -exec chmod 644 {} +
chmod 755 "$HOME_DIR/engine/bin/tiger-headless" "$HOME_DIR/install.sh" "$HOME_DIR/uninstall.sh"

# ---- 3. host defaults (never overwrite an existing file) ---------------------------------------
mkdir -p /etc/tigerwhm && chmod 755 /etc/tigerwhm
if [ ! -f /etc/tigerwhm/config.json ]; then
  /usr/local/cpanel/3rdparty/bin/php -r 'define("TIGERWHM_ENGINE", "/opt/tigerwhm/engine"); require "/opt/tigerwhm/lib/autoload.php"; TigerWHM_Config::save(TigerWHM_Config::defaults());'
fi
chmod 644 /etc/tigerwhm/config.json
mkdir -p /var/cpanel/tigerwhm && chmod 700 /var/cpanel/tigerwhm

# ---- 4. cPanel side (the account holder's page) ------------------------------------------------
theme_root="/usr/local/cpanel/base/frontend/jupiter"
mkdir -p "$theme_root/tiger"
cp -a "$HOME_DIR/cpanel/tiger/." "$theme_root/tiger/"
chmod 755 "$theme_root/tiger"; chmod 644 "$theme_root/tiger/"*
# The LEFT-MENU entry: cPanel's MenuBuilder scans /var/cpanel/plugins/*/menu/LeftMenu.yaml and
# install_plugin rebuilds the menu cache when the tarball carries a menu/ dir — so our copy must be
# in place BEFORE install_plugin runs. (install_plugin itself does not copy plugin dirs there.)
mkdir -p /var/cpanel/plugins/tigerwhm
cp -a "$HOME_DIR/cpanel/install.json" "$HOME_DIR/cpanel/tiger.png" "$HOME_DIR/cpanel/menu" /var/cpanel/plugins/tigerwhm/
chmod -R a+rX /var/cpanel/plugins/tigerwhm
# install_plugin wants a tarball with install.json + the icon (+ menu/) at its top level.
( cd "$HOME_DIR/cpanel" && tar -czf "$work/tigerwhm-cpanel.tar.gz" install.json tiger.png menu )
/usr/local/cpanel/scripts/install_plugin "$work/tigerwhm-cpanel.tar.gz" --theme jupiter

# ---- 5. WHM side (the host's page) -------------------------------------------------------------
whm_cgi="/usr/local/cpanel/whostmgr/docroot/cgi/tiger"
mkdir -p "$whm_cgi"
cp -a "$HOME_DIR/whm/." "$whm_cgi/"
chmod 755 "$whm_cgi" "$whm_cgi/index.cgi"; chmod 644 "$whm_cgi/tiger.png"
mkdir -p /usr/local/cpanel/whostmgr/docroot/addon_plugins
cp "$HOME_DIR/whm/tiger.png" /usr/local/cpanel/whostmgr/docroot/addon_plugins/tiger.png
/usr/local/cpanel/bin/register_appconfig "$HOME_DIR/appconfig/tigerwhm.conf"

echo
echo "TigerWHM $ver installed."
echo "  WHM    → Plugins → Tiger"
echo "  cPanel → left menu → Tiger AI Site Management   (every account, Jupiter theme)"
echo "  Host defaults: /etc/tigerwhm/config.json"
