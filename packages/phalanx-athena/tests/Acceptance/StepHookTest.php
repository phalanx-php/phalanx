<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Acceptance;

use Phalanx\Athena\Activity;
use Phalanx\Athena\Hook\StepContext;
use Phalanx\Athena\Hook\StepHook;
use Phalanx\Athena\Hook\StepHookResult;
use Phalanx\Athena\Tests\Fixtures\ScopeStub;
use Phalanx\Athena\Tests\Fixtures\SyncRuntimeFactory;
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
use Phalanx\Scope\TaskScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StepHookTest extends TestCase
{
    #[Test]
    public function hooksFireBeforeAndAfterEachInvocationAndCue(): void
    {
        $at = new \DateTimeImmutable('2026-05-18T10:00:00Z');

        $provider = new Provider([
            new TokenDelta('cue_h1', 1, 'act_hook', null, 'athena-test-agent', $at, 'hoplite '),
            new TokenDelta('cue_h2', 2, 'act_hook', null, 'athena-test-agent', $at, 'formation'),
            new TokenStop('cue_h3', 3, 'act_hook', null, 'athena-test-agent', $at, StopReason::EndOfTurn),
        ], Capabilities::empty());

        $counter = new InvocationCounterHook();
        $loop    = new Loop(new DefaultBuilder(), $provider, new SyncRuntimeFactory(), hooks: [$counter]);

        $result = $loop(new ScopeStub(), new TestAgent(), new Activity\Config('act_hook', Context::new(), 1));

        self::assertSame(Activity\State::Completed, $result->state);
        self::assertGreaterThan(0, $counter->calls, 'Hook must be called at least once');
    }

    #[Test]
    public function hookReturningTerminalOutcomeHaltsLoopWithThatOutcome(): void
    {
        $at = new \DateTimeImmutable('2026-05-18T10:00:00Z');

        $provider = new Provider([
            new TokenDelta('cue_h1', 1, 'act_halt', null, 'athena-test-agent', $at, 'partial'),
            new TokenDelta('cue_h2', 2, 'act_halt', null, 'athena-test-agent', $at, 'more'),
            new TokenStop('cue_h3', 3, 'act_halt', null, 'athena-test-agent', $at, StopReason::EndOfTurn),
        ], Capabilities::empty());

        $halt = new HaltAfterFirstCueHook();
        $loop = new Loop(new DefaultBuilder(), $provider, new SyncRuntimeFactory(), hooks: [$halt]);

        $result = $loop(new ScopeStub(), new TestAgent(), new Activity\Config('act_halt', Context::new(), 1));

        self::assertSame(Activity\State::Failed, $result->state);
        self::assertSame(Outcome::Failed, $result->outcome);
        self::assertNotNull($result->error);
        self::assertStringContainsString('halted by policy', $result->error->getMessage());
    }
}

final class InvocationCounterHook implements StepHook
{
    public int $calls = 0;

    public function __invoke(TaskScope $scope, StepContext $context): StepHookResult
    {
        $this->calls++;
        return StepHookResult::continue();
    }
}

final class HaltAfterFirstCueHook implements StepHook
{
    private bool $seen = false;

    public function __invoke(TaskScope $scope, StepContext $context): StepHookResult
    {
        if ($context->cue !== null && !$this->seen) {
            $this->seen = true;
            return StepHookResult::fail(new \RuntimeException('halted by policy'));
        }

        return StepHookResult::continue();
    }
}
