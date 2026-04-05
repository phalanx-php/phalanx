<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$sdkPath = $GLOBALS['DAEMON8_SDK_PATH'] ?? '';

if ($sdkPath !== '' && is_dir($sdkPath)) {
    require_once $sdkPath . '/DaemonAI.php';
    require_once $sdkPath . '/functions.php';
}
