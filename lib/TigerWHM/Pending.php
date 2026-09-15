<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * One install attempt's resources, remembered until the install succeeds. The database + user are
 * created BEFORE the engine runs (its requirements gate proves the connection), so if a later step
 * stops, the next attempt for the same domain must reuse them — not create them again and die on
 * "already exists" before the engine can resume its own ledger. Lives in the account's home at 0600;
 * the same credentials end up in that account's local.ini anyway.
 */
class TigerWHM_Pending
{
    public static function path($home, $domain)
    {
        return rtrim($home, '/') . '/.tigerwhm/pending/' . preg_replace('/[^a-z0-9.-]/i', '_', strtolower((string) $domain)) . '.json';
    }

    /** @return array|null {db:{name,user,password}, app_root, since} */
    public static function load($home, $domain)
    {
        $f = self::path($home, $domain);
        if (!is_file($f)) { return null; }
        $j = json_decode((string) @file_get_contents($f), true);
        return is_array($j) && is_array($j['db'] ?? null) && ($j['db']['name'] ?? '') !== '' ? $j : null;
    }

    public static function save($home, $domain, array $db, $appRoot)
    {
        $f = self::path($home, $domain);
        if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0700, true); }
        $ok = @file_put_contents($f, json_encode(['db' => $db, 'app_root' => $appRoot, 'since' => date('c')], JSON_UNESCAPED_SLASHES)) !== false;
        if ($ok) { @chmod($f, 0600); }
        return $ok;
    }

    public static function clear($home, $domain)
    {
        $f = self::path($home, $domain);
        if (is_file($f)) { @unlink($f); }
    }
}
