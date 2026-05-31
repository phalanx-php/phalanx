<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Swoole\Coroutine;
use Swoole\Runtime;

final class RuntimeHookOwnershipFixture
{
    public function configureHooks(): int
    {
        Runtime::enableCoroutine(true, SWOOLE_HOOK_TCP);
        Coroutine::set(['hook_flags' => SWOOLE_HOOK_STDIO]);

        $a = SWOOLE_HOOK_TCP;

        return $a;
    }
}
