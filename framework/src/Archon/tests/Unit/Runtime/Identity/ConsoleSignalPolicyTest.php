<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Unit\Runtime\Identity;

use InvalidArgumentException;
use Phalanx\Archon\Runtime\Identity\ConsoleSignalPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConsoleSignalPolicyTest extends TestCase
{
    #[Test]
    public function defaultPolicyMapsInterruptAndTerminateWhenPcntlIsAvailable(): void
    {
        $policy = ConsoleSignalPolicy::default();

        if (!function_exists('pcntl_signal')) {
            self::assertSame([], $policy->exitCodes());
            return;
        }

        if (defined('SIGINT')) {
            $signal = $policy->signal(SIGINT);

            self::assertNotNull($signal);
            self::assertSame(130, $signal->exitCode);
            self::assertSame('signal:int', $signal->reason);
        }

        if (defined('SIGTERM')) {
            $signal = $policy->signal(SIGTERM);

            self::assertNotNull($signal);
            self::assertSame(143, $signal->exitCode);
            self::assertSame('signal:term', $signal->reason);
        }
    }

    #[Test]
    public function customPolicySupportsDeterministicTestSignals(): void
    {
        $signal = ConsoleSignalPolicy::forSignals([12 => 140])->signal(12);

        self::assertNotNull($signal);
        self::assertSame(12, $signal->number);
        self::assertSame(140, $signal->exitCode);
        self::assertSame('signal:12', $signal->reason);
    }

    #[Test]
    public function customPolicyRejectsInvalidSignals(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ConsoleSignalPolicy::forSignals([0 => 130]);
    }

    #[Test]
    public function customPolicyRejectsSignalsUnsupportedByPcntl(): void
    {
        if (!function_exists('pcntl_signal_get_handler')) {
            self::markTestSkipped('Unsupported signal validation requires pcntl.');
        }

        $this->expectException(InvalidArgumentException::class);

        ConsoleSignalPolicy::forSignals([999 => 130]);
    }
}
