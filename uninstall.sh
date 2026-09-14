#!/bin/bash
# SPDX-License-Identifier: BSD-3-Clause
# TigerWHM — remove the plugin. Leaves every installed Tiger site, every database, and
# /etc/tigerwhm/config.json (your host defaults) untouched; pass --purge-config to remove the latter.
set -uo pipefail
[ "$(id -u)" = "0" ] || { echo "run as root" >&2; exit 1; }
/usr/local/cpanel/bin/unregister_appconfig tigerwhm 2>/dev/null || true
rm -rf /usr/local/cpanel/whostmgr/docroot/cgi/tiger /usr/local/cpanel/whostmgr/docroot/addon_plugins/tiger.png
if [ -f /opt/tigerwhm/cpanel/install.json ]; then
  work="$(mktemp -d)"; ( cd /opt/tigerwhm/cpanel && tar -czf "$work/p.tar.gz" install.json tiger.png )
  /usr/local/cpanel/scripts/uninstall_plugin "$work/p.tar.gz" --theme jupiter 2>/dev/null || true
  rm -rf "$work"
fi
rm -rf /usr/local/cpanel/base/frontend/jupiter/tiger /var/cpanel/plugins/tigerwhm-install /var/cpanel/tigerwhm
[ "${1:-}" = "--purge-config" ] && rm -rf /etc/tigerwhm
rm -rf /opt/tigerwhm
echo "TigerWHM removed. Installed Tiger sites were not touched."
