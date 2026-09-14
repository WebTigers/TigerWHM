<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * Request + response plumbing shared by the two pages. The WHM CGI runs under php-cli (no
 * superglobals), the cPanel page under LivePHP (superglobals present) — this class reads either.
 * Also: a per-user nonce for POSTs (cPanel's session token protects the URL, not the form), and
 * escaping, because every value on these pages came from a form or a filesystem.
 */
class TigerWHM_Http
{
    /** @var array */
    public $get = [];
    /** @var array */
    public $post = [];
    /** @var string */
    public $method = 'GET';

    public static function fromEnv()
    {
        $r = new self();
        $r->method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!empty($_GET) || !empty($_POST)) { $r->get = $_GET; $r->post = $_POST; return $r; }
        parse_str((string) ($_SERVER['QUERY_STRING'] ?? getenv('QUERY_STRING') ?: ''), $r->get);
        if ($r->method === 'POST') {
            $body = (string) file_get_contents('php://stdin');
            if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? getenv('CONTENT_TYPE') ?: ''), 'application/x-www-form-urlencoded') !== false || $body !== '') {
                parse_str($body, $r->post);
            }
        }
        return $r;
    }

    public function p($k, $d = '') { return isset($this->post[$k]) ? (is_array($this->post[$k]) ? $this->post[$k] : trim((string) $this->post[$k])) : $d; }
    public function g($k, $d = '') { return isset($this->get[$k])  ? trim((string) $this->get[$k]) : $d; }

    /** HTML-escape. */
    public static function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    /** A nonce held in a 0600 file the page's OS user owns; rotates daily. */
    public static function nonce($file)
    {
        $dir = dirname($file);
        if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
        $n = is_file($file) && filemtime($file) > time() - 86400 ? trim((string) @file_get_contents($file)) : '';
        if (!preg_match('/^[a-f0-9]{32}$/', $n)) { $n = bin2hex(random_bytes(16)); @file_put_contents($file, $n); @chmod($file, 0600); }
        return $n;
    }

    public static function nonceOk($file, $given)
    {
        $n = is_file($file) ? trim((string) @file_get_contents($file)) : '';
        return $n !== '' && hash_equals($n, (string) $given);
    }

    /** CGI response header (php-cli mode only; LivePHP emits its own). */
    public static function cgiHeader($type = 'text/html; charset=utf-8')
    {
        if (PHP_SAPI === 'cli') { echo "Content-Type: {$type}\r\n\r\n"; }   // php-cli as a CGI: we own the header
    }
}
