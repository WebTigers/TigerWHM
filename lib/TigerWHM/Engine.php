<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * Run the vendored tiger-headless engine — under the ACCOUNT's PHP (cPanel's own PHP is what runs
 * these pages, and it is not what serves the site), and, from WHM, as the account user. The plugin
 * holds NO install logic: every verb is a subprocess of the engine and every answer is its JSON.
 */
class TigerWHM_Engine
{
    /** Where install.sh puts things. */
    const HOME = '/opt/tigerwhm';

    /** @var string */
    protected $_bin;
    /** @var string the PHP binary to run the engine with */
    protected $_php;
    /** @var string|null run as this system user (root only) */
    protected $_user;
    /** @var callable|null test seam: fn(string $cmd, ?string $stdin): array{0:int,1:string,2:string} */
    public static $exec;

    public function __construct($php, $user = null, $bin = null)
    {
        $this->_php  = (string) $php;
        $this->_user = $user !== null ? (string) $user : null;
        $this->_bin  = $bin ?: self::HOME . '/engine/bin/tiger-headless';
    }

    /** The newest EasyApache PHP ≥ the host minimum on this box, e.g. /opt/cpanel/ea-php82/root/usr/bin/php. */
    public static function newestPhp($min = 'ea-php81')
    {
        $best = null; $bestN = 0; $minN = (int) preg_replace('/\D/', '', $min);
        foreach (glob('/opt/cpanel/ea-php*/root/usr/bin/php') ?: [] as $p) {
            if (preg_match('#/ea-php(\d+)/#', $p, $m) && (int) $m[1] >= $minN && (int) $m[1] > $bestN) { $bestN = (int) $m[1]; $best = $p; }
        }
        return $best;
    }

    /** The binary for a vhost's assigned version ("ea-php81"), or null if it is below the minimum / absent. */
    public static function phpFor($version, $min = 'ea-php81')
    {
        if (!preg_match('/^ea-php(\d+)$/', (string) $version, $m)) { return null; }
        if ((int) $m[1] < (int) preg_replace('/\D/', '', $min)) { return null; }
        $p = "/opt/cpanel/{$version}/root/usr/bin/php";
        return is_executable($p) ? $p : null;
    }

    /** @return array the decoded JSON result (or a synthesized failure when the engine did not answer) */
    public function run($verb, array $flags = [], $stdin = null)
    {
        $cmd = escapeshellarg($this->_php) . ' -d display_errors=stderr ' . escapeshellarg($this->_bin) . ' ' . escapeshellarg($verb);
        foreach ($flags as $k => $v) {
            $cmd .= ' ' . escapeshellarg('--' . $k . ($v === true ? '' : '=' . $v));
        }
        if ($this->_user !== null) {
            // From WHM (root): become the account. -s because a cPanel account's shell is often noshell.
            $cmd = 'su -s /bin/sh ' . escapeshellarg($this->_user) . ' -c ' . escapeshellarg($cmd);
        }
        list($exit, $out, $err) = self::$exec ? call_user_func(self::$exec, $cmd, $stdin) : self::_proc($cmd, $stdin);
        $json = json_decode($out, true);
        if (!is_array($json)) {
            return ['ok' => false, 'verb' => $verb, 'steps' => [], 'error' => ['step' => 'engine', 'message' => trim($err) !== '' ? trim($err) : "the installer produced no result (exit {$exit})"], 'exit' => $exit];
        }
        $json['exit'] = $exit;
        return $json;
    }

    /** Convenience verbs. */
    public function check(array $spec)      { return $this->run('check',   ['spec' => '-'], json_encode($spec)); }
    public function install(array $spec)    { return $this->run('install', ['spec' => '-'], json_encode($spec)); }
    public function status($appRoot)        { return $this->run('status',  ['app-root' => $appRoot]); }
    public function login($appRoot, $email = '') { return $this->run('login', ['app-root' => $appRoot] + ($email !== '' ? ['email' => $email] : [])); }
    public function upgrade($appRoot, $v='') { return $this->run('upgrade', ['app-root' => $appRoot] + ($v !== '' ? ['version' => $v] : [])); }
    public function discover($root, $updates = false, $depth = 4)
    {
        return $this->run('discover', ['root' => $root, 'depth' => (string) $depth] + ($updates ? ['check-updates' => true] : []));
    }

    protected static function _proc($cmd, $stdin)
    {
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = proc_open($cmd, $desc, $pipes, null, null);
        if (!is_resource($p)) { return [127, '', 'could not start the installer']; }
        if ($stdin !== null) { fwrite($pipes[0], $stdin); }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        return [proc_close($p), (string) $out, (string) $err];
    }
}
