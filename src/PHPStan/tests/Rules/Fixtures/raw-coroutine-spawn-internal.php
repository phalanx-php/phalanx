<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Swoole\Coroutine;

final class RawCoroutineSpawnInternalFixture
{
    public function spawn(): void
    {
        Coroutine::create(static function (): void {
        });
    }
}
