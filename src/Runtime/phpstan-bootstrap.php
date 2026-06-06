<?php

declare(strict_types=1);

if (!defined('ITIMER_REAL')) {
    define('ITIMER_REAL', 0);
}

foreach (
    [
        'SWOOLE_HOOK_PDO_PGSQL' => 65536,
        'SWOOLE_HOOK_PDO_ODBC' => 131072,
        'SWOOLE_HOOK_PDO_ORACLE' => 262144,
        'SWOOLE_HOOK_PDO_SQLITE' => 524288,
        'SWOOLE_HOOK_PDO_FIREBIRD' => 1048576,
        'SWOOLE_HOOK_NET_FUNCTION' => 2097152,
        'SWOOLE_HOOK_MONGODB' => 4194304,
    ] as $constant => $value
) {
    if (!defined($constant)) {
        define($constant, $value);
    }
}
