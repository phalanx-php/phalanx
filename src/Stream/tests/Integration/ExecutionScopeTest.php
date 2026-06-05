<?php

declare(strict_types=1);

namespace Phalanx\Stream\Tests\Integration;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Stream\Channel;
use Phalanx\Stream\Emitter;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ExecutionScopeTest extends PhalanxTestCase
{
    #[Test]
    public function executionScopeIsTheStreamContract(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            self::assertInstanceOf(ExecutionScope::class, $scope);
        });
    }

    #[Test]
    public function emitterDrivesEndToEndPipelineUnderRealScope(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            $emitter = Emitter::produce(static function (Channel $ch): void {
                foreach (['a', 'b', 'c'] as $val) {
                    $ch->emit($val);
                }
            })->map(static fn(string $v): string => strtoupper($v));

            self::assertSame(['A', 'B', 'C'], iterator_to_array($emitter($scope)));
        });
    }
}
