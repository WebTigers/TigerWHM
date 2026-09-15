<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * The install form's checkbox list, driven by the Directory feed (WebTigers/TigerVendors) so a new
 * free module appears in the plugin without a plugin release. Filtered to free installables; never
 * hardcoded. Cached per user so the form doesn't hit GitHub on every render.
 */
class TigerWHM_Directory
{
    const REPO      = 'WebTigers/TigerVendors';
    const INDEX_URL = 'https://raw.githubusercontent.com/WebTigers/TigerVendors/main/data/index.json';
    const CACHE_TTL = 3600;
    /** Seconds a page render may spend here (see TigerWHM_Catalog::FETCH_TIMEOUT). */
    const FETCH_TIMEOUT = 5;

    /** The feed URL for the host's trust mode: `main` when live, the pinned commit otherwise. */
    public static function url(?array $cfg = null)
    {
        $t = $cfg['catalog'] ?? [];
        return ($t['mode'] ?? 'live') === 'pinned' ? 'https://raw.githubusercontent.com/' . self::REPO . '/' . $t['directory_ref'] . '/data/index.json' : self::INDEX_URL;
    }

    /**
     * @param  string|null $cacheDir a writable dir for the cache (the account's home; null = no cache)
     * @param  array|null  $cfg      host defaults (trust mode); null = live
     * @return array{themes:array,modules:array,available:bool}
     */
    public static function installables($cacheDir = null, ?array $cfg = null)
    {
        $json = null; $url = self::url($cfg);
        $cache = $cacheDir ? rtrim($cacheDir, '/') . '/.tigerwhm-directory' . (($cfg['catalog']['mode'] ?? '') === 'pinned' ? '-' . substr($cfg['catalog']['directory_ref'], 0, 12) : '') . '.json' : null;
        if ($cache && is_file($cache) && filemtime($cache) > time() - self::CACHE_TTL) {
            $json = (string) @file_get_contents($cache);
        }
        if ($json === null || $json === '') {
            list($body, $code) = Tiger_Headless_Http::get($url, 'application/json', self::FETCH_TIMEOUT);
            if ($body !== null && $code < 400 && json_decode($body, true)) {
                $json = $body;
                if ($cache) { @file_put_contents($cache, $json); @chmod($cache, 0600); }
            } elseif ($cache && is_file($cache)) {
                $json = (string) @file_get_contents($cache);   // stale is better than nothing
            }
        }
        return self::filter(json_decode((string) $json, true));
    }

    /** Pure: the feed → the two checkbox lists. */
    public static function filter($index)
    {
        $out = ['themes' => [], 'modules' => [], 'available' => is_array($index) && !empty($index['modules'])];
        if (!$out['available']) { return $out; }
        foreach ($index['modules'] as $m) {
            if (!is_array($m)) { continue; }
            $slug  = (string) ($m['slug'] ?? '');
            $model = (string) ($m['pricing']['model'] ?? 'free');
            $type  = (string) ($m['type'] ?? '');
            if ($slug === '' || $model !== 'free' || empty($m['repository'])) { continue; }
            if (!in_array($type, ['theme', 'app', 'plugin', 'code'], true)) { continue; }   // SDK/developer packs are not end-user installables
            $row = [
                'slug'        => $slug,
                'name'        => (string) ($m['module'] ?? $m['name'] ?? $slug),
                'description' => (string) ($m['description'] ?? ''),
                'version'     => (string) ($m['version'] ?? ''),
                'category'    => (string) ($m['category'] ?? ''),
            ];
            if ($type === 'theme') { $out['themes'][] = $row; } else { $out['modules'][] = $row; }
        }
        usort($out['themes'],  static function ($a, $b) { return strcmp($a['name'], $b['name']); });
        usort($out['modules'], static function ($a, $b) { return strcmp($a['name'], $b['name']); });
        return $out;
    }
}
