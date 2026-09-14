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
    const INDEX_URL = 'https://raw.githubusercontent.com/WebTigers/TigerVendors/main/data/index.json';
    const CACHE_TTL = 3600;

    /**
     * @param  string|null $cacheDir a writable dir for the cache (the account's home; null = no cache)
     * @return array{themes:array,modules:array,available:bool}
     */
    public static function installables($cacheDir = null)
    {
        $json = null;
        $cache = $cacheDir ? rtrim($cacheDir, '/') . '/.tigerwhm-directory.json' : null;
        if ($cache && is_file($cache) && filemtime($cache) > time() - self::CACHE_TTL) {
            $json = (string) @file_get_contents($cache);
        }
        if ($json === null || $json === '') {
            list($body, $code) = Tiger_Headless_Http::get(self::INDEX_URL, 'application/json');
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
