<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * The out-of-the-gate offer — read LIVE from the public Directory repo (TigerVendors/install/catalog.json)
 * so adding a skill pack or changing what is featured reaches every new install with no plugin update
 * on any host. Themes and modules come from the Directory feed (TigerWHM_Directory); this file curates
 * what is pre-selected and defines the skill packs. Cached per user; a bundled snapshot is the last
 * resort when GitHub is unreachable and there is no cache.
 */
class TigerWHM_Catalog
{
    const URL       = 'https://raw.githubusercontent.com/WebTigers/TigerVendors/main/install/catalog.json';
    const CACHE_TTL = 3600;
    const SNAPSHOT  = __DIR__ . '/../../cpanel/catalog.snapshot.json';

    /** @return array{featured:array{theme:string,modules:array},skill_packs:array,live:bool} */
    public static function load($cacheDir = null)
    {
        $json = null; $live = false;
        $cache = $cacheDir ? rtrim($cacheDir, '/') . '/.tigerwhm-catalog.json' : null;
        if ($cache && is_file($cache) && filemtime($cache) > time() - self::CACHE_TTL) {
            $json = (string) @file_get_contents($cache); $live = true;
        }
        if ($json === null || $json === '') {
            list($body, $code) = Tiger_Headless_Http::get(self::URL, 'application/json');
            if ($body !== null && $code < 400 && is_array(json_decode($body, true))) {
                $json = $body; $live = true;
                if ($cache) { @file_put_contents($cache, $json); @chmod($cache, 0600); }
            } elseif ($cache && is_file($cache)) {
                $json = (string) @file_get_contents($cache); $live = true;   // stale beats absent
            }
        }
        if (($json === null || $json === '') && is_file(self::SNAPSHOT)) { $json = (string) file_get_contents(self::SNAPSHOT); }
        $out = self::normalize(json_decode((string) $json, true));
        $out['live'] = $live;
        return $out;
    }

    /** Pure: shape-check the document so a typo upstream degrades to "no packs", never a fatal page. */
    public static function normalize($doc)
    {
        $out = ['featured' => ['theme' => '', 'modules' => []], 'skill_packs' => []];
        if (!is_array($doc)) { return $out; }
        $slug = static function ($v) { $v = strtolower(trim((string) $v)); return preg_match('/^[a-z0-9][a-z0-9_-]*$/', $v) ? $v : ''; };
        $f = is_array($doc['featured'] ?? null) ? $doc['featured'] : [];
        $out['featured']['theme']   = $slug($f['theme'] ?? '');
        $out['featured']['modules'] = array_values(array_filter(array_map($slug, is_array($f['modules'] ?? null) ? $f['modules'] : [])));
        foreach ((is_array($doc['skill_packs'] ?? null) ? $doc['skill_packs'] : []) as $p) {
            if (!is_array($p)) { continue; }
            $id = $slug($p['id'] ?? '');
            if ($id === '') { continue; }
            $skills = [];
            foreach ((is_array($p['skills'] ?? null) ? $p['skills'] : []) as $s) {
                if (!is_array($s)) { continue; }
                $repo = trim((string) ($s['repo'] ?? '')); $path = trim((string) ($s['path'] ?? ''), '/'); $ref = trim((string) ($s['ref'] ?? 'main'));
                if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) || $path === '' || strpos($path, '..') !== false) { continue; }
                $skills[] = ['repo' => $repo, 'path' => $path, 'ref' => $ref !== '' ? $ref : 'main'];
            }
            if (!$skills) { continue; }
            $out['skill_packs'][] = [
                'id' => $id, 'name' => trim(strip_tags((string) ($p['name'] ?? $id))), 'description' => trim(strip_tags((string) ($p['description'] ?? ''))),
                'default' => !empty($p['default']), 'skills' => $skills,
            ];
        }
        return $out;
    }

    /** Expand chosen pack ids into the flat, deduplicated `skills` list the engine takes. */
    public static function skillsFor(array $catalog, array $packIds)
    {
        $out = []; $seen = [];
        foreach ($catalog['skill_packs'] as $p) {
            if (!in_array($p['id'], $packIds, true)) { continue; }
            foreach ($p['skills'] as $s) {
                $k = strtolower($s['repo'] . '@' . $s['path']);
                if (isset($seen[$k])) { continue; }
                $seen[$k] = true; $out[] = $s;
            }
        }
        return $out;
    }
}
