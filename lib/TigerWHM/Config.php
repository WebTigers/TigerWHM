<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * Host defaults — what the server owner sets once in WHM and every account's install form starts
 * from: which theme/modules are pre-ticked, the default locale, a branding line, a mail relay.
 * Lives at /etc/tigerwhm/config.json, root-owned, WORLD-READABLE — the cPanel-side page runs as the
 * account user and must read it. So it never holds a secret; a relay password does not belong here.
 */
class TigerWHM_Config
{
    const PATH = '/etc/tigerwhm/config.json';

    /** The defaults a fresh install of the plugin ships with. */
    public static function defaults()
    {
        return [
            'theme'       => null,          // null = follow the catalog's featured theme
            'modules'     => null,          // null = follow the catalog's featured modules
            'skill_packs' => null,          // null = the catalog's default packs
            'locale'      => 'en',
            'branding'    => '',            // one line shown on the account's install page ("Provided by Acme Hosting")
            'mail'        => ['transport' => '', 'host' => '', 'port' => 25],   // a relay the host runs; blank = the box's MTA
            'min_php'     => 'ea-php81',
            'allow_agent' => true,          // may an account tick "connect an AI agent" (the MCP handshake)?
            // What the install form is allowed to trust. 'live' reads the catalog + Directory from `main` (an
            // hour's cache): additions reach every host with no plugin update. 'pinned' reads both at the
            // commits named here and nothing else — the host reviews, then moves the pin.
            'catalog'     => ['mode' => 'live', 'catalog_ref' => '', 'directory_ref' => ''],
        ];
    }

    /** @return array the merged defaults (file over shipped) */
    public static function load($path = null)
    {
        $path = $path ?: self::PATH;
        $d = self::defaults();
        if (is_file($path)) {
            $j = json_decode((string) @file_get_contents($path), true);
            if (is_array($j)) { $d = self::normalize(array_replace($d, $j)); }
        }
        return $d;
    }

    /** Validate + coerce a candidate config (from the WHM form or the file). */
    public static function normalize(array $c)
    {
        $d = self::defaults();
        $slug = static function ($v) { $v = strtolower(trim((string) $v)); return preg_match('/^[a-z0-9][a-z0-9_-]*$/', $v) ? $v : ''; };
        $out = [
            'theme'       => array_key_exists('theme', $c) && $c['theme'] !== null ? $slug($c['theme']) : null,
            'modules'     => is_array($c['modules'] ?? null) ? array_values(array_unique(array_filter(array_map($slug, $c['modules'])))) : null,
            'skill_packs' => is_array($c['skill_packs'] ?? null) ? array_values(array_unique(array_filter(array_map($slug, $c['skill_packs'])))) : null,
            'locale'      => preg_match('/^[a-z]{2,3}$/', (string) ($c['locale'] ?? '')) ? (string) $c['locale'] : $d['locale'],
            'branding'    => trim(strip_tags((string) ($c['branding'] ?? ''))),
            'mail'        => [
                'transport' => in_array((string) (($c['mail']['transport'] ?? '')), ['', 'smtp'], true) ? (string) ($c['mail']['transport'] ?? '') : '',
                'host'      => preg_match('/^[a-z0-9.\-]*$/i', (string) ($c['mail']['host'] ?? '')) ? (string) ($c['mail']['host'] ?? '') : '',
                'port'      => max(1, min(65535, (int) ($c['mail']['port'] ?? 25))),
            ],
            'min_php'     => preg_match('/^ea-php\d{2,3}$/', (string) ($c['min_php'] ?? '')) ? (string) $c['min_php'] : $d['min_php'],
            'allow_agent' => !empty($c['allow_agent']),
            'catalog'     => self::_catalog(is_array($c['catalog'] ?? null) ? $c['catalog'] : []),
        ];
        return $out;
    }

    /** The trust block: pinned needs two 40-hex commits, otherwise it is live. */
    protected static function _catalog(array $c)
    {
        $sha = static function ($v) { $v = strtolower(trim((string) $v)); return preg_match('/^[0-9a-f]{40}$/', $v) ? $v : ''; };
        $out = ['mode' => 'live', 'catalog_ref' => $sha($c['catalog_ref'] ?? ''), 'directory_ref' => $sha($c['directory_ref'] ?? '')];
        if (($c['mode'] ?? '') === 'pinned' && $out['catalog_ref'] !== '' && $out['directory_ref'] !== '') { $out['mode'] = 'pinned'; }
        return $out;
    }

    /** Write (root only). */
    public static function save(array $c, $path = null)
    {
        $path = $path ?: self::PATH;
        $c = self::normalize($c);
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0755, true)) { return false; }
        $ok = @file_put_contents($path, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") !== false;
        if ($ok) { @chmod($path, 0644); }
        return $ok;
    }

    /**
     * The effective pre-selections for the install form: the host's override when set, else the
     * catalog's featured/default choices. Returns {theme, modules, skill_packs}.
     */
    public static function preselect(array $c, array $catalog)
    {
        return [
            'theme'       => $c['theme'] !== null ? $c['theme'] : ($catalog['featured']['theme'] ?? ''),
            'modules'     => $c['modules'] !== null ? $c['modules'] : ($catalog['featured']['modules'] ?? []),
            'skill_packs' => $c['skill_packs'] !== null ? $c['skill_packs'] : array_column(array_filter($catalog['skill_packs'] ?? [], static function ($p) { return !empty($p['default']); }), 'id'),
        ];
    }

    /** The `config` map for a headless spec from the mail defaults (only what is set). */
    public static function specConfig(array $c)
    {
        $out = [];
        if (($c['mail']['transport'] ?? '') === 'smtp' && ($c['mail']['host'] ?? '') !== '') {
            $out['mail.transport'] = 'smtp';
            $out['mail.smtp.host'] = $c['mail']['host'];
            $out['mail.smtp.port'] = (string) $c['mail']['port'];
            $out['mail.smtp.ssl']  = '';      // a host relay on the LAN: no TLS, no auth (a password can't live in a world-readable file)
            $out['mail.smtp.auth'] = '';
        }
        return $out;
    }
}
