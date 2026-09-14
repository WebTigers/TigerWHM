<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * The host's side (WHM, root): every Tiger on the server, who owns it, what it runs on, and
 * updating them — one at a time or all. Discovery is the engine's `discover` over /home; the
 * account and vhost come from WHM's own view of the box (whmapi1), never guessed from paths alone.
 */
class TigerWHM_Fleet
{
    /** @var callable fn(string $fn, array $args): array — whmapi1 JSON `data` (seam for tests) */
    protected $_whm;
    /** @var array host defaults */
    protected $_cfg;
    /** @var string */
    protected $_php;

    public function __construct(array $config, ?callable $whmapi = null, $php = null)
    {
        $this->_cfg = $config;
        $this->_whm = $whmapi ?: [self::class, 'whmapi1'];
        $this->_php = $php ?: TigerWHM_Engine::newestPhp($config['min_php'] ?? 'ea-php81');
    }

    /** Shell out to whmapi1 (root). */
    public static function whmapi1($fn, array $args = [])
    {
        $cmd = '/usr/local/cpanel/bin/whmapi1 ' . escapeshellarg($fn) . ' --output=json';
        foreach ($args as $k => $v) { $cmd .= ' ' . escapeshellarg($k . '=' . $v); }
        $out = (string) shell_exec($cmd . ' 2>/dev/null');
        $j = json_decode($out, true);
        return is_array($j) && !empty($j['metadata']['result']) ? ($j['data'] ?? []) : [];
    }

    /** vhost → {account, homedir, documentroot, version} from WHM. */
    public function vhosts()
    {
        $out = [];
        foreach ((array) (call_user_func($this->_whm, 'php_get_vhost_versions', [])['versions'] ?? []) as $v) {
            if (!is_array($v) || empty($v['vhost'])) { continue; }
            $out[(string) $v['vhost']] = [
                'account' => (string) ($v['account'] ?? ''), 'homedir' => (string) ($v['homedir'] ?? ''),
                'documentroot' => (string) ($v['documentroot'] ?? ''), 'version' => (string) ($v['version'] ?? ''),
                'main' => !empty($v['main_domain']), 'suspended' => !empty($v['is_suspended']),
            ];
        }
        return $out;
    }

    /**
     * The fleet: discover() joined to WHM's vhost table so each row carries its account, domain and
     * PHP; plus the summary the engine already computed.
     */
    public function installs($checkUpdates = true)
    {
        $engine = new TigerWHM_Engine($this->_php);
        $r = $engine->discover('/home', $checkUpdates, 4);
        $byDoc = []; $byHome = [];
        foreach ($this->vhosts() as $vhost => $v) {
            if ($v['documentroot'] !== '') { $byDoc[rtrim($v['documentroot'], '/')] = $vhost; }
            if ($v['homedir'] !== '')      { $byHome[rtrim($v['homedir'], '/')][] = $vhost; }
        }
        $vh = $this->vhosts();
        $rows = [];
        foreach ((array) ($r['installs'] ?? []) as $i) {
            $doc = rtrim((string) ($i['docroot'] ?? ''), '/');
            $vhost = $byDoc[$doc] ?? '';
            $home = preg_match('#^(/home/[^/]+)#', (string) $i['app_root'], $m) ? $m[1] : '';
            $account = $vhost !== '' ? $vh[$vhost]['account'] : basename($home);
            $rows[] = $i + [
                'account' => $account, 'vhost' => $vhost, 'home' => $home,
                'php'     => $vhost !== '' ? $vh[$vhost]['version'] : '',
                'php_bin' => TigerWHM_Engine::phpFor($vhost !== '' ? $vh[$vhost]['version'] : '', $this->_cfg['min_php'] ?? 'ea-php81'),
            ];
        }
        return ['ok' => !empty($r['ok']), 'error' => $r['error'] ?? null, 'summary' => $r['summary'] ?? [], 'installs' => $rows];
    }

    /** Update one install: as its account user, under its vhost's PHP (or the newest acceptable one). */
    public function upgrade(array $row, $version = '')
    {
        $php  = $row['php_bin'] ?: $this->_php;
        $user = (string) $row['account'];
        if ($user === '' || $user === 'root') { return ['ok' => false, 'error' => ['step' => 'account', 'message' => 'Cannot determine the account that owns ' . $row['app_root']]]; }
        return (new TigerWHM_Engine($php, $user))->upgrade((string) $row['app_root'], $version);
    }

    /** Accounts whose main domain runs a PHP below the host minimum — the requirements gate, fleet-wide. */
    public function belowMinimum()
    {
        $min = (int) preg_replace('/\D/', '', $this->_cfg['min_php'] ?? 'ea-php81');
        $out = [];
        foreach ($this->vhosts() as $vhost => $v) {
            if ($v['main'] && (int) preg_replace('/\D/', '', $v['version']) < $min) { $out[] = ['account' => $v['account'], 'vhost' => $vhost, 'version' => $v['version']]; }
        }
        return $out;
    }
}
