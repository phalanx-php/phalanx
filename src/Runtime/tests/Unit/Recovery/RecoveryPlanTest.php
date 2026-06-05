<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Recovery;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Mark\Mark;
use Phalanx\Recovery\Backoff;
use Phalanx\Recovery\Circuit;
use Phalanx\Recovery\CircuitKey;
use Phalanx\Recovery\Jitter;
use Phalanx\Recovery\RecoveryPlan;
use Phalanx\Recovery\RecoveryPreset;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RecoveryPlanTest extends TestCase
{
    #[Test]
    public function noneHasNoAttemptsBudgetOrDeadline(): void
    {
        $plan = RecoveryPlan::none();

        self::assertTrue($plan->isNone());
        self::assertNull($plan->attempts);
        self::assertNull($plan->deadline);
    }

    #[Test]
    public function failFastHasSingleAttempt(): void
    {
        $plan = RecoveryPlan::failFast(deadline: Mark::s(5));

        self::assertSame(1, $plan->attempts);
        self::assertSame(5000, $plan->deadline->toMilliseconds());
    }

    #[Test]
    public function defaultRetryHasExplicitDefaults(): void
    {
        $plan = RecoveryPlan::defaultRetry();

        self::assertSame(3, $plan->attempts);
        self::assertNull($plan->attemptTimeout);
        self::assertNull($plan->deadline);
        self::assertNotNull($plan->backoff);
        self::assertNotNull($plan->jitter);
    }

    #[Test]
    public function defaultRetryAcceptsAllParameters(): void
    {
        $plan = RecoveryPlan::defaultRetry(
            attempts: 5,
            attemptTimeout: Mark::s(2),
            deadline: Mark::s(30),
            backoff: Backoff::fixed(Mark::ms(500)),
            jitter: Jitter::none(),
        );

        self::assertSame(5, $plan->attempts);
        self::assertSame(2000, $plan->attemptTimeout->toMilliseconds());
        self::assertSame(30000, $plan->deadline->toMilliseconds());
    }

    #[Test]
    public function pollingCarriesInterval(): void
    {
        $plan = RecoveryPlan::polling(
            interval: Mark::ms(250),
            deadline: Mark::s(30),
        );

        self::assertSame(250, $plan->pollInterval->toMilliseconds());
        self::assertSame(30000, $plan->deadline->toMilliseconds());
        self::assertNull($plan->attempts);
    }

    #[Test]
    public function longRunningHasSingleAttemptNoTimeout(): void
    {
        $plan = RecoveryPlan::longRunning(deadline: Mark::s(600));

        self::assertSame(1, $plan->attempts);
        self::assertNull($plan->attemptTimeout);
        self::assertSame(600000, $plan->deadline->toMilliseconds());
    }

    #[Test]
    public function withDeadlineReturnsCopy(): void
    {
        $original = RecoveryPlan::defaultRetry();
        $modified = $original->withDeadline(Mark::s(10));

        self::assertNull($original->deadline);
        self::assertSame(10000, $modified->deadline->toMilliseconds());
    }

    #[Test]
    public function withAttemptTimeoutReturnsCopy(): void
    {
        $original = RecoveryPlan::defaultRetry();
        $modified = $original->withAttemptTimeout(Mark::s(2));

        self::assertNull($original->attemptTimeout);
        self::assertSame(2000, $modified->attemptTimeout->toMilliseconds());
    }

    #[Test]
    public function withBackoffReturnsCopy(): void
    {
        $original = RecoveryPlan::defaultRetry();
        $modified = $original->withBackoff(Backoff::fixed(Mark::ms(500)));

        self::assertNotSame($original->backoff, $modified->backoff);
    }

    #[Test]
    public function retryingOnReturnsCopy(): void
    {
        $original = RecoveryPlan::defaultRetry();
        $filtered = $original->retryingOn(RuntimeException::class);

        self::assertSame([], $original->retryOnTypes());
        self::assertSame([RuntimeException::class], $filtered->retryOnTypes());
    }

    #[Test]
    public function cancelledIsNeverRetried(): void
    {
        $plan = RecoveryPlan::defaultRetry();

        self::assertFalse($plan->shouldRetry(new Cancelled('test')));
    }

    #[Test]
    public function allNonCancelledRetriedByDefault(): void
    {
        $plan = RecoveryPlan::defaultRetry();

        self::assertTrue($plan->shouldRetry(new RuntimeException('boom')));
    }

    #[Test]
    public function retryOnFilterLimitsRetryableTypes(): void
    {
        $plan = RecoveryPlan::defaultRetry()->retryingOn(RuntimeException::class);

        self::assertTrue($plan->shouldRetry(new RuntimeException('boom')));
        self::assertFalse($plan->shouldRetry(new \LogicException('nope')));
    }

    #[Test]
    public function onEventReturnsCopy(): void
    {
        $original = RecoveryPlan::defaultRetry();
        $modified = $original->onEvent(static fn() => null);

        self::assertNull($original->eventCallback());
        self::assertNotNull($modified->eventCallback());
    }

    #[Test]
    public function circuitReturnsCopy(): void
    {
        $original = RecoveryPlan::defaultRetry();
        $modified = $original->circuit(Circuit::named(CircuitKey::from('test')));

        self::assertNull($original->circuitConfig());
        self::assertNotNull($modified->circuitConfig());
    }

    #[Test]
    public function effectiveBackoffCombinesJitter(): void
    {
        $plan = RecoveryPlan::defaultRetry(
            backoff: Backoff::fixed(Mark::ms(100)),
            jitter: Jitter::percent(10, static fn(): float => 1.0),
        );

        $effective = $plan->effectiveBackoff();

        self::assertSame(110, $effective->delayFor(0)->toMilliseconds());
    }

    #[Test]
    public function presetDefaultRetryMatchesFactory(): void
    {
        $preset = RecoveryPreset::DefaultRetry->toPlan();

        self::assertSame(3, $preset->attempts);
        self::assertNotNull($preset->backoff);
    }

    #[Test]
    public function presetFailFastMatchesFactory(): void
    {
        $preset = RecoveryPreset::FailFast->toPlan();

        self::assertSame(1, $preset->attempts);
    }

    #[Test]
    public function presetPollingMatchesFactory(): void
    {
        $preset = RecoveryPreset::Polling->toPlan();

        self::assertSame(250, $preset->pollInterval->toMilliseconds());
    }

    #[Test]
    public function presetLongRunningMatchesFactory(): void
    {
        $preset = RecoveryPreset::LongRunning->toPlan();

        self::assertSame(1, $preset->attempts);
        self::assertNull($preset->attemptTimeout);
        self::assertNull($preset->deadline);
    }

    #[Test]
    public function noneIsNone(): void
    {
        self::assertTrue(RecoveryPlan::none()->isNone());
    }

    #[Test]
    public function failFastIsNotNone(): void
    {
        self::assertFalse(RecoveryPlan::failFast()->isNone());
    }

    #[Test]
    public function defaultRetryIsNotNone(): void
    {
        self::assertFalse(RecoveryPlan::defaultRetry()->isNone());
    }
}
