<?php

declare(strict_types=1);

namespace Phalanx\Cli\Tests\Unit\Doctor;

use Phalanx\Cli\Doctor\Check;
use Phalanx\Cli\Doctor\CheckStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CheckTest extends TestCase
{
    #[Test]
    public function passFactoryProducesPassStatus(): void
    {
        $check = Check::pass('PHP Version', '8.4.1');

        self::assertSame('PHP Version', $check->name);
        self::assertSame(CheckStatus::Pass, $check->status);
        self::assertSame('8.4.1', $check->message);
        self::assertNull($check->remediation);
    }

    #[Test]
    public function passFactoryDefaultMessage(): void
    {
        $check = Check::pass('test');

        self::assertSame('ok', $check->message);
    }

    #[Test]
    public function warnFactoryProducesWarnStatus(): void
    {
        $check = Check::warn('PIE', 'Not found', 'Install PIE');

        self::assertSame(CheckStatus::Warn, $check->status);
        self::assertSame('Not found', $check->message);
        self::assertSame('Install PIE', $check->remediation);
    }

    #[Test]
    public function warnRemediationIsOptional(): void
    {
        $check = Check::warn('test', 'degraded');

        self::assertNull($check->remediation);
    }

    #[Test]
    public function failFactoryProducesFailStatus(): void
    {
        $check = Check::fail('OpenSwoole', 'Not loaded', 'Install via PIE');

        self::assertSame(CheckStatus::Fail, $check->status);
        self::assertSame('Not loaded', $check->message);
        self::assertSame('Install via PIE', $check->remediation);
    }

    #[Test]
    public function failRemediationIsOptional(): void
    {
        $check = Check::fail('fatal', 'broken');

        self::assertNull($check->remediation);
    }

    #[Test]
    public function isPassOnlyTrueForPass(): void
    {
        self::assertTrue(Check::pass('t')->isPass());
        self::assertFalse(Check::warn('t', 'w')->isPass());
        self::assertFalse(Check::fail('t', 'f')->isPass());
    }

    #[Test]
    public function isFailOnlyTrueForFail(): void
    {
        self::assertTrue(Check::fail('t', 'f')->isFail());
        self::assertFalse(Check::pass('t')->isFail());
        self::assertFalse(Check::warn('t', 'w')->isFail());
    }

    #[Test]
    public function isWarnOnlyTrueForWarn(): void
    {
        self::assertTrue(Check::warn('t', 'w')->isWarn());
        self::assertFalse(Check::pass('t')->isWarn());
        self::assertFalse(Check::fail('t', 'f')->isWarn());
    }
}
