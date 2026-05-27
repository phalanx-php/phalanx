<?php

declare(strict_types=1);

namespace Phalanx\Substrate\Swoole;

use Phalanx\Substrate\RuntimeHookDriver;
use Phalanx\Substrate\RuntimeHookFlags;
use Swoole\Runtime;

final class SwooleRuntimeHookDriver implements RuntimeHookDriver
{
    private ?RuntimeHookFlags $flags = null;

    public function enableCoroutine(int $flags): void
    {
        Runtime::enableCoroutine($flags);
    }

    public function getHookFlags(): int
    {
        return Runtime::getHookFlags();
    }

    public function hookFlagNames(): array
    {
        return [
            SWOOLE_HOOK_TCP => 'tcp',
            SWOOLE_HOOK_UDP => 'udp',
            SWOOLE_HOOK_UNIX => 'unix',
            SWOOLE_HOOK_SSL => 'ssl',
            SWOOLE_HOOK_TLS => 'tls',
            SWOOLE_HOOK_FILE => 'file',
            SWOOLE_HOOK_SLEEP => 'sleep',
            SWOOLE_HOOK_CURL => 'curl',
            SWOOLE_HOOK_BLOCKING_FUNCTION => 'blocking',
            SWOOLE_HOOK_ALL => 'all',
        ];
    }

    public function hookFlags(): RuntimeHookFlags
    {
        return $this->flags ??= new RuntimeHookFlags(
            tcp: SWOOLE_HOOK_TCP,
            udp: SWOOLE_HOOK_UDP,
            unix: SWOOLE_HOOK_UNIX,
            ssl: SWOOLE_HOOK_SSL,
            tls: SWOOLE_HOOK_TLS,
            file: SWOOLE_HOOK_FILE,
            sleep: SWOOLE_HOOK_SLEEP,
            curl: SWOOLE_HOOK_CURL,
            blocking: SWOOLE_HOOK_BLOCKING_FUNCTION,
            all: SWOOLE_HOOK_ALL,
        );
    }
}
