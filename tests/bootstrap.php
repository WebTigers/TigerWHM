<?php
// The engine is vendored by build.sh; for tests, use the sibling checkout or the built copy.
$engine = is_dir(__DIR__ . '/../engine/src') ? __DIR__ . '/../engine' : __DIR__ . '/../../TigerHeadless';
define('TIGERWHM_ENGINE', $engine);
require __DIR__ . '/../lib/autoload.php';
