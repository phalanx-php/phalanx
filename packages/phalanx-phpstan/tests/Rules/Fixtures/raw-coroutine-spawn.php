<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use OpenSwoole\Coroutine;
use Phalanx\Scope\ExecutionScope;

final class RawCoroutineSpawnFixture
{
    public function spawn(ExecutionScope $scope): void
    {
        Coroutine::create(static function (): void {
        });

        \Swoole\Coroutine::create(static function (): void {
        });

        $scope->go(static function (): void {
        });
    }
}
