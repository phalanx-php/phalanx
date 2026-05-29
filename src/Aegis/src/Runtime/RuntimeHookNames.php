<?php

declare(strict_types=1);

namespace Phalanx\Runtime;

final class RuntimeHookNames
{
    private const array NAMES = [
        SWOOLE_HOOK_TCP => 'TCP',
        SWOOLE_HOOK_UDP => 'UDP',
        SWOOLE_HOOK_UNIX => 'UNIX',
        SWOOLE_HOOK_UDG => 'UDG',
        SWOOLE_HOOK_SSL => 'SSL',
        SWOOLE_HOOK_TLS => 'TLS',
        SWOOLE_HOOK_STREAM_FUNCTION => 'STREAM_FUNCTION',
        SWOOLE_HOOK_FILE => 'FILE',
        SWOOLE_HOOK_PROC => 'PROC',
        SWOOLE_HOOK_SLEEP => 'SLEEP',
        SWOOLE_HOOK_CURL => 'CURL',
        SWOOLE_HOOK_NATIVE_CURL => 'NATIVE_CURL',
        // OpenSwoole called this HOOK_BLOCKING_FUNCTION; Swoole 6 equivalent is SWOOLE_HOOK_NET_FUNCTION.
        SWOOLE_HOOK_NET_FUNCTION => 'NET_FUNCTION',
        SWOOLE_HOOK_SOCKETS => 'SOCKETS',
        SWOOLE_HOOK_STDIO => 'STDIO',
    ];

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function forMask(int $mask): array
    {
        $names = [];
        foreach (self::NAMES as $flag => $name) {
            if (($mask & $flag) === $flag) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
