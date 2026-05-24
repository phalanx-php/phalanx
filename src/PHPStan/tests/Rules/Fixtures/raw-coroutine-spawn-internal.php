<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use OpenSwoole\Coroutine;

final class RawCoroutineSpawnInternalFixture
{
    public function spawn(): void
    {
        Coroutine::create(static function (): void {
        });
    }
}
