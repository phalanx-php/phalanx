<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Boot;

use Phalanx\Boot\BootEvaluation;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class BootEvaluationTest extends PhalanxTestCase
{
    #[Test]
    public function passFactoryProducesPassStatus(): void
    {
        $ev = BootEvaluation::pass('everything looks good');

        self::assertSame('pass', $ev->status);
        self::assertSame('everything looks good', $ev->message);
        self::assertNull($ev->remediation);
    }

    #[Test]
    public function passFactoryDefaultMessageIsOk(): void
    {
        $ev = BootEvaluation::pass();

        self::assertSame('ok', $ev->message);
    }

    #[Test]
    public function warnFactoryProducesWarnStatus(): void
    {
        $ev = BootEvaluation::warn('service degraded', 'check the logs');

        self::assertSame('warn', $ev->status);
        self::assertSame('service degraded', $ev->message);
        self::assertSame('check the logs', $ev->remediation);
    }

    #[Test]
    public function warnFactoryRemediationIsOptional(): void
    {
        $ev = BootEvaluation::warn('something degraded');

        self::assertSame('warn', $ev->status);
        self::assertNull($ev->remediation);
    }

    #[Test]
    public function failFactoryProducesFailStatus(): void
    {
        $ev = BootEvaluation::fail('database missing', 'set DB_URL');

        self::assertSame('fail', $ev->status);
        self::assertSame('database missing', $ev->message);
        self::assertSame('set DB_URL', $ev->remediation);
    }

    #[Test]
    public function failFactoryRemediationIsOptional(): void
    {
        $ev = BootEvaluation::fail('fatal');

        self::assertSame('fail', $ev->status);
        self::assertNull($ev->remediation);
    }

    #[Test]
    public function isPassTrueOnlyForPassStatus(): void
    {
        self::assertTrue(BootEvaluation::pass()->isPass());
        self::assertFalse(BootEvaluation::warn('w')->isPass());
        self::assertFalse(BootEvaluation::fail('f')->isPass());
    }

    #[Test]
    public function isFailTrueOnlyForFailStatus(): void
    {
        self::assertTrue(BootEvaluation::fail('f')->isFail());
        self::assertFalse(BootEvaluation::pass()->isFail());
        self::assertFalse(BootEvaluation::warn('w')->isFail());
    }

    #[Test]
    public function isWarnTrueOnlyForWarnStatus(): void
    {
        self::assertTrue(BootEvaluation::warn('w')->isWarn());
        self::assertFalse(BootEvaluation::pass()->isWarn());
        self::assertFalse(BootEvaluation::fail('f')->isWarn());
    }
}
