<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * The one seam to cPanel's UAPI. The cPanel page hands in the LivePHP `CPANEL` object; tests hand
 * in an array of canned answers. Either way `uapi()` returns the call's `data` and THROWS on a
 * failed call with cPanel's own error text — a caller never has to read the envelope.
 */
class TigerWHM_Cpanel
{
    /** @var object|null the LivePHP CPANEL instance */
    protected $_live;
    /** @var array<string,mixed> canned: "Module::func" => data | Closure(args) */
    protected $_fake;
    /** @var array<int,array> every call made (tests assert on it) */
    public $calls = [];

    public static function live($cpanel)        { $o = new self(); $o->_live = $cpanel; return $o; }
    public static function fake(array $answers) { $o = new self(); $o->_fake = $answers; return $o; }

    /**
     * @param  string $module
     * @param  string $func
     * @param  array  $args
     * @return mixed  the call's data
     * @throws RuntimeException with cPanel's error text
     */
    public function uapi($module, $func, array $args = [])
    {
        $this->calls[] = [$module, $func, $args];
        if ($this->_fake !== null) {
            $key = $module . '::' . $func;
            if (!array_key_exists($key, $this->_fake)) { throw new RuntimeException("UAPI {$key} is not available."); }
            $a = $this->_fake[$key];
            return $a instanceof Closure ? $a($args) : $a;
        }
        $r = $this->_live->uapi($module, $func, $args);
        $res = $r['cpanelresult']['result'] ?? null;
        if (!is_array($res) || empty($res['status'])) {
            $errs = $res['errors'] ?? ($r['cpanelresult']['error'] ?? null);
            throw new RuntimeException("{$module}::{$func} failed: " . (is_array($errs) ? implode('; ', $errs) : (string) $errs));
        }
        return $res['data'] ?? null;
    }
}
