<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Recovery;

use Phalanx\Mark\Mark;
use Phalanx\Recovery\RecoveryAction;
use Phalanx\Recovery\RecoveryDecision;
use Phalanx\Recovery\RecoveryPlan;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RecoveryDecisionTest extends TestCase
{
    #[Test]
    public function actionExposed(): void
    {
        $decision = new RecoveryDecision(RecoveryAction::Retry);

        self::assertSame(RecoveryAction::Retry, $decision->action);
    }

    #[Test]
    public function delayExposed(): void
    {
        $decision = new RecoveryDecision(RecoveryAction::Delay, delay: Mark::s(5));

        self::assertNotNull($decision->delay);
        self::assertSame(5000, $decision->delay->toMilliseconds());
    }

    #[Test]
    public function errorExposed(): void
    {
        $error = new RuntimeException('test');
        $decision = new RecoveryDecision(RecoveryAction::Fail, error: $error);

        self::assertSame($error, $decision->error);
    }

    #[Test]
    public function nextPlanExposed(): void
    {
        $plan = RecoveryPlan::failFast();
        $decision = new RecoveryDecision(RecoveryAction::Retry, nextPlan: $plan);

        self::assertSame($plan, $decision->nextPlan);
    }

    #[Test]
    public function defaultsAreNull(): void
    {
        $decision = new RecoveryDecision(RecoveryAction::Continue);

        self::assertNull($decision->delay);
        self::assertNull($decision->nextPlan);
        self::assertNull($decision->parameters);
        self::assertNull($decision->error);
    }
}
