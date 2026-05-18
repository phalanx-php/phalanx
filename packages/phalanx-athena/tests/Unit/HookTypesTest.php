<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit;

use Phalanx\Athena\Hook\StepHookResult;
use Phalanx\Athena\Turn\Outcome;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HookTypesTest extends TestCase
{
    #[Test]
    public function hookResultDefaultsToContinue(): void
    {
        self::assertSame(Outcome::Continue, StepHookResult::continue()->outcome);
    }

    #[Test]
    public function stopRequiresTerminalOutcome(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StepHookResult::stop(Outcome::Continue);
    }

    #[Test]
    public function failStoresThrowable(): void
    {
        $error  = new \RuntimeException('failed');
        $result = StepHookResult::fail($error);

        self::assertSame(Outcome::Failed, $result->outcome);
        self::assertSame($error, $result->error);
    }
}
