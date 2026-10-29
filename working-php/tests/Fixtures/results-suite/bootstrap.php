<?php

// Minimal bootstrap: no database, no lifecycle; only autoloading and the base path
define('BASE_PATH', dirname(__DIR__, 3));
require BASE_PATH . '/vendor/autoload.php';
date_default_timezone_set(\App\Integrity\HashChainMicroShardingBuilder::APP_TIMEZONE);
