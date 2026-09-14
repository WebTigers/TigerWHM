#!/bin/bash
# SPDX-License-Identifier: BSD-3-Clause
# Build the release tarball: the plugin + a vendored tiger-headless engine (a tagged release, never a
# working copy). Output: dist/tigerwhm-<version>.tar.gz + .sha256. CI runs this on a v* tag.
#   ./build.sh [--engine v0.3.0]
set -euo pipefail
cd "$(dirname "$0")"
ENGINE_TAG="${ENGINE_TAG:-}"
while [ $# -gt 0 ]; do case "$1" in --engine) ENGINE_TAG="$2"; shift 2;; *) echo "unknown option $1" >&2; exit 2;; esac; done
[ -n "$ENGINE_TAG" ] || ENGINE_TAG="$(cat ENGINE_VERSION)"
ver="$(cat VERSION)"
rm -rf engine dist && mkdir -p engine dist
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
curl -fsSL -A tigerwhm-build -o "$tmp/engine.tar.gz" "https://github.com/WebTigers/TigerHeadless/archive/refs/tags/${ENGINE_TAG}.tar.gz"
tar -xzf "$tmp/engine.tar.gz" -C "$tmp"
src="$(find "$tmp" -maxdepth 1 -type d -name 'TigerHeadless-*' | head -1)"
cp -a "$src/bin" "$src/src" "$src/LICENSE" engine/
echo "$ENGINE_TAG" > engine/ENGINE_VERSION
name="tigerwhm-${ver}"
mkdir -p "$tmp/$name"
cp -a VERSION LICENSE README.md install.sh uninstall.sh lib engine cpanel whm appconfig "$tmp/$name/"
tar -czf "dist/${name}.tar.gz" -C "$tmp" "$name"
digest="$( (shasum -a 256 "dist/${name}.tar.gz" 2>/dev/null || sha256sum "dist/${name}.tar.gz") | awk '{print $1}')"
printf '%s  %s\n' "$digest" "${name}.tar.gz" > "dist/${name}.tar.gz.sha256"
echo "built dist/${name}.tar.gz (engine ${ENGINE_TAG})"; cat "dist/${name}.tar.gz.sha256"
