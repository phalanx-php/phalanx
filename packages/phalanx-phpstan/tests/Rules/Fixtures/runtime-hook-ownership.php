<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use OpenSwoole\Coroutine;
use OpenSwoole\Runtime;

final class RuntimeHookOwnershipFixture
{
    public function configureHooks(): int
    {
        Runtime::enableCoroutine(true, Runtime::HOOK_TCP);
        Coroutine::set(['hook_flags' => Runtime::HOOK_STDIO]);

        return SWOOLE_HOOK_TCP;
    }
}
