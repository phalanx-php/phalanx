<?php

declare(strict_types=1);

$autoload = null;
foreach ([dirname(__DIR__) . '/vendor/autoload.php', dirname(__DIR__, 3) . '/vendor/autoload.php'] as $candidate) {
    if (is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
}

if ($autoload === null) {
    throw new RuntimeException('Cannot find autoload.php');
}

require $autoload;

foreach (['ITIMER_REAL' => 0, 'ITIMER_VIRTUAL' => 1, 'ITIMER_PROF' => 2] as $constant => $value) {
    if (!defined($constant)) {
        define($constant, $value);
    }
}

// SWOOLE_HOOK_NET_FUNCTION (2^21 = 2097152) is defined by ext-swoole 6 but absent
// from the swoole/ide-helper stubs. Declaring it here keeps PHPStan analysis clean.
// Swoole 6 renamed OpenSwoole's HOOK_BLOCKING_FUNCTION to this constant.
if (!defined('SWOOLE_HOOK_NET_FUNCTION')) {
    define('SWOOLE_HOOK_NET_FUNCTION', 2097152);
}
