<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Integration;

use Phalanx\Athena\Activity;
use Phalanx\Athena\Tests\Fixtures\TestAgent;
use Phalanx\Athena\Turn\DefaultBuilder;
use Phalanx\Athena\Turn\Loop;
use Phalanx\Athena\Turn\Outcome;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Panoply\Provider\Fake\Provider;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class TurnLoopRuntimeTest extends PhalanxTestCase
{
    #[Test]
    public function turnLoopRunsInsideAegisScopeWithoutRuntimeLeaks(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $provider = new Provider([
            new TokenDelta('cue_1', 1, 'act_1', null, 'athena-test-agent', $at, 'scoped'),
            new TokenStop('cue_2', 2, 'act_1', null, 'athena-test-agent', $at, StopReason::EndOfTurn),
        ], Capabilities::empty());

        $result = $this->scope->run(static function (ExecutionScope $scope) use ($provider): Activity\Result {
            $loop = new Loop(new DefaultBuilder(), $provider);

            return $loop($scope, new TestAgent(), new Activity\Config('act_1', Context::new(), 1));
        });

        self::assertSame(Activity\State::Completed, $result->state);
        self::assertSame(Outcome::Complete, $result->outcome);
        $this->scope->expect->runtime()->clean();
    }
}
