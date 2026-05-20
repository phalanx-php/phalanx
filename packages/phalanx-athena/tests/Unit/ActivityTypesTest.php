<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit;

use Phalanx\Athena\Activity\Config;
use Phalanx\Athena\Activity\Result;
use Phalanx\Athena\Activity\State;
use Phalanx\Athena\Activity\TerminalCell;
use Phalanx\Athena\Activity\TerminalState;
use Phalanx\Athena\Hook\StepContext;
use Phalanx\Athena\Hook\StepHook;
use Phalanx\Athena\Hook\StepHookResult;
use Phalanx\Athena\Turn\Outcome;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Conversation\Log;
use Phalanx\Scope\TaskScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ActivityTypesTest extends TestCase
{
    #[Test]
    public function configCarriesActivityBoundary(): void
    {
        $hook = new class implements StepHook {
            public function __invoke(TaskScope $scope, StepContext $context): StepHookResult
            {
                return StepHookResult::continue();
            }
        };

        $config = new Config('act_1', Context::new(), 2, 10.0, [$hook]);

        self::assertSame('act_1', $config->id);
        self::assertSame(2, $config->maxInvocations);
        self::assertSame(10.0, $config->timeoutSeconds);
        self::assertSame([$hook], $config->hooks);
    }

    #[Test]
    public function resultCarriesTerminalState(): void
    {
        $result = new Result('act_1', State::Completed, Outcome::Complete, Log::from([]), 1);

        self::assertSame(State::Completed, $result->state);
        self::assertSame(Outcome::Complete, $result->outcome);
        self::assertSame(1, $result->invocations);
    }

    #[Test]
    public function maxInvocationsMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Config('act_1', Context::new(), 0);
    }

    #[Test]
    public function timeoutMustBePositiveWhenPresent(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Config('act_1', Context::new(), timeoutSeconds: 0.0);
    }

    #[Test]
    public function terminalCellRejectsDoubleResolve(): void
    {
        $cell = new TerminalCell();
        $state = new TerminalState(State::Completed, Outcome::Complete, Log::from([]), 1);

        $cell->resolve($state);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('TerminalCell has already been resolved.');

        $cell->resolve($state);
    }

    #[Test]
    public function terminalCellStartsUnresolved(): void
    {
        $cell = new TerminalCell();

        self::assertNull($cell->value);
    }
}
