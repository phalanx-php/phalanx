<?php

declare(strict_types=1);

namespace Phalanx\Runtime;

use OpenSwoole\Runtime;

final class RuntimeHookNames
{
    private const array NAMES = [
        Runtime::HOOK_TCP => 'TCP',
        Runtime::HOOK_UDP => 'UDP',
        Runtime::HOOK_UNIX => 'UNIX',
        Runtime::HOOK_UDG => 'UDG',
        Runtime::HOOK_SSL => 'SSL',
        Runtime::HOOK_TLS => 'TLS',
        Runtime::HOOK_STREAM_FUNCTION => 'STREAM_FUNCTION',
        Runtime::HOOK_FILE => 'FILE',
        Runtime::HOOK_PROC => 'PROC',
        Runtime::HOOK_SLEEP => 'SLEEP',
        Runtime::HOOK_CURL => 'CURL',
        Runtime::HOOK_NATIVE_CURL => 'NATIVE_CURL',
        Runtime::HOOK_BLOCKING_FUNCTION => 'BLOCKING_FUNCTION',
        Runtime::HOOK_SOCKETS => 'SOCKETS',
        Runtime::HOOK_STDIO => 'STDIO',
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
