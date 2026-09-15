<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * The install record: one JSON line per completed install in the account's home — which core, which
 * modules and theme, which skills at which commit, and what the catalog/Directory were read from
 * (live `main` or a pinned commit). The answer to "what did this account actually get, and from where".
 */
class TigerWHM_Log
{
    public static function path($home) { return rtrim($home, '/') . '/.tigerwhm/installs.log'; }

    public static function install($home, $domain, array $spec, array $result, array $cfg, ?array $catalog = null)
    {
        $row = [
            'time'     => date('c'), 'domain' => $domain, 'app_root' => $spec['paths']['app_root'] ?? '',
            'version'  => $result['version'] ?? ($result['core'] ?? ''),
            'theme'    => $spec['theme'] ?? '', 'modules' => $spec['modules'] ?? [],
            'skills'   => $result['skills']['sources'] ?? [],
            'trust'    => ['mode' => $cfg['catalog']['mode'] ?? 'live', 'catalog' => $catalog['source'] ?? TigerWHM_Catalog::url($cfg), 'directory' => TigerWHM_Directory::url($cfg)],
        ];
        $f = self::path($home);
        if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0700, true); }
        $ok = @file_put_contents($f, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX) !== false;
        if ($ok) { @chmod($f, 0600); }
        return $ok;
    }
}
