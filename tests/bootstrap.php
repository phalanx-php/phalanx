<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

if (!defined('ITIMER_REAL')) {
    define('ITIMER_REAL', 0);
}

$sdkPath = ($GLOBALS['DAEMON8_SDK_PATH'] ?? '') ?: (getenv('DAEMON8_SDK_PATH') ?: '');

if ($sdkPath !== '' && is_dir($sdkPath)) {
    require_once $sdkPath . '/Daemon8.php';
    require_once $sdkPath . '/functions.php';
}
