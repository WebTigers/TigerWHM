<?php
/** Dependency-free autoloader for TigerWHM_* plus the vendored tiger-headless engine. */
spl_autoload_register(static function ($class) {
    if (strpos($class, 'TigerWHM_') === 0) {
        $f = __DIR__ . '/' . str_replace('_', '/', $class) . '.php';
        if (is_file($f)) { require $f; }
    }
});
$engine = defined('TIGERWHM_ENGINE') ? TIGERWHM_ENGINE : dirname(__DIR__) . '/engine';
if (is_file($engine . '/src/autoload.php')) { require_once $engine . '/src/autoload.php'; }
