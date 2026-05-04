<?php

declare(strict_types=1);

namespace Phalanx\Styx\Tests\Integration;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Stream\StreamContext;
use Phalanx\Styx\Channel;
use Phalanx\Styx\Emitter;
use Phalanx\Styx\Tests\Support\AsyncTestCase;
use PHPUnit\Framework\Attributes\Test;

final class StreamContextWithRealScopeTest extends AsyncTestCase
{
    #[Test]
    public function executionScopeSatisfiesStreamContractContract(): void
    {
        $this->runScoped(static function (ExecutionScope $scope): void {
            self::assertInstanceOf(StreamContext::class, $scope);
        });
    }

    #[Test]
    public function emitterDrivesEndToEndPipelineUnderRealScope(): void
    {
        $this->runScoped(static function (ExecutionScope $scope): void {
            $emitter = Emitter::produce(static function (Channel $ch): void {
                foreach (['a', 'b', 'c'] as $val) {
                    $ch->emit($val);
                }
            })->map(static fn(string $v): string => strtoupper($v));

            self::assertSame(['A', 'B', 'C'], iterator_to_array($emitter($scope)));
        });
    }
}
