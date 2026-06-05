<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Unit\Runtime\Identity;

use InvalidArgumentException;
use Phalanx\Console\Runtime\Identity\SignalPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SignalPolicyTest extends TestCase
{
    #[Test]
    public function defaultPolicyMapsInterruptAndTerminateWhenExtSwooleIsAvailable(): void
    {
        $policy = SignalPolicy::default();

        if (!extension_loaded('swoole')) {
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
        $signal = SignalPolicy::forSignals([12 => 140])->signal(12);

        self::assertNotNull($signal);
        self::assertSame(12, $signal->number);
        self::assertSame(140, $signal->exitCode);
        self::assertSame('signal:12', $signal->reason);
    }

    #[Test]
    public function customPolicyRejectsInvalidSignals(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SignalPolicy::forSignals([0 => 130]);
    }

    #[Test]
    public function customPolicyRejectsSignalsUnsupportedByPcntl(): void
    {
        if (!function_exists('pcntl_signal_get_handler')) {
            self::markTestSkipped('Unsupported signal validation requires pcntl.');
        }

        $this->expectException(InvalidArgumentException::class);

        SignalPolicy::forSignals([999 => 130]);
    }
}
