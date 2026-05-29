<?php

declare(strict_types=1);

if (!defined('ITIMER_REAL')) {
    define('ITIMER_REAL', 0);
}

// SWOOLE_HOOK_NET_FUNCTION (2^21 = 2097152) is defined by ext-swoole 6 but absent
// from the swoole/ide-helper stubs. Declaring it here keeps PHPStan analysis clean.
// Swoole 6 renamed OpenSwoole's HOOK_BLOCKING_FUNCTION to this constant.
if (!defined('SWOOLE_HOOK_NET_FUNCTION')) {
    define('SWOOLE_HOOK_NET_FUNCTION', 2097152);
}
