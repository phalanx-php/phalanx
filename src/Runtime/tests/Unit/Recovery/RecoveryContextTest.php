<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Recovery;

use Phalanx\Mark\Mark;
use Phalanx\Recovery\RecoveryAction;
use Phalanx\Recovery\RecoveryContext;
use Phalanx\Recovery\RecoveryPlan;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RecoveryContextTest extends TestCase
{
    #[Test]
    public function continueReturnsContinueAction(): void
    {
        $ctx = $this->context();

        $decision = $ctx->continue();

        self::assertSame(RecoveryAction::Continue, $decision->action);
        self::assertNull($decision->delay);
    }

    #[Test]
    public function retryReturnsRetryAction(): void
    {
        $ctx = $this->context();

        $decision = $ctx->retry();

        self::assertSame(RecoveryAction::Retry, $decision->action);
        self::assertNull($decision->delay);
    }

    #[Test]
    public function retryWithDelayCarriesDelay(): void
    {
        $ctx = $this->context();

        $decision = $ctx->retry(Mark::s(5));

        self::assertSame(RecoveryAction::Retry, $decision->action);
        self::assertSame(5000, $decision->delay->toMilliseconds());
    }

    #[Test]
    public function delayReturnsDelayAction(): void
    {
        $ctx = $this->context();

        $decision = $ctx->delay(Mark::s(30));

        self::assertSame(RecoveryAction::Delay, $decision->action);
        self::assertSame(30000, $decision->delay->toMilliseconds());
    }

    #[Test]
    public function pollReturnsPollAction(): void
    {
        $ctx = $this->context();

        $decision = $ctx->poll(Mark::ms(250));

        self::assertSame(RecoveryAction::Poll, $decision->action);
        self::assertSame(250, $decision->delay->toMilliseconds());
    }

    #[Test]
    public function cancelReturnsCancelAction(): void
    {
        $ctx = $this->context();

        $decision = $ctx->cancel();

        self::assertSame(RecoveryAction::Cancel, $decision->action);
    }

    #[Test]
    public function failReturnsFailActionWithContextError(): void
    {
        $error = new RuntimeException('boom');
        $ctx = $this->context(error: $error);

        $decision = $ctx->fail();

        self::assertSame(RecoveryAction::Fail, $decision->action);
        self::assertSame($error, $decision->error);
    }

    #[Test]
    public function failWithExplicitErrorOverridesContextError(): void
    {
        $ctx = $this->context(error: new RuntimeException('original'));
        $override = new RuntimeException('override');

        $decision = $ctx->fail($override);

        self::assertSame($override, $decision->error);
    }

    #[Test]
    public function propertiesExposed(): void
    {
        $elapsed = Mark::ms(500);
        $remaining = Mark::s(9);
        $error = new RuntimeException('test');
        $plan = RecoveryPlan::defaultRetry();

        $ctx = new RecoveryContext(
            attempt: 2,
            elapsed: $elapsed,
            remainingDeadline: $remaining,
            error: $error,
            taskName: 'fetch-profile',
            plan: $plan,
        );

        self::assertSame(2, $ctx->attempt);
        self::assertTrue($ctx->elapsed->eq($elapsed));
        self::assertTrue($ctx->remainingDeadline->eq($remaining));
        self::assertSame($error, $ctx->error);
        self::assertSame('fetch-profile', $ctx->taskName);
        self::assertSame($plan, $ctx->plan);
    }

    private function context(?RuntimeException $error = null): RecoveryContext
    {
        return new RecoveryContext(
            attempt: 1,
            elapsed: Mark::ms(100),
            remainingDeadline: Mark::s(10),
            error: $error,
            taskName: 'test-task',
            plan: RecoveryPlan::defaultRetry(),
        );
    }
}
