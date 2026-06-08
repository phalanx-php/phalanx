<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Unit\Runtime;

use Phalanx\Console\Runtime\SignalPolicy;
use Phalanx\Console\Runtime\SignalTrap;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SignalTrapTest extends TestCase
{
    #[Test]
    public function disabledTrapIsANoop(): void
    {
        $trap = SignalTrap::install(SignalPolicy::disabled(), static function (): void {
            self::fail('Disabled signal trap should not register callbacks.');
        });

        $trap->restore();

        self::assertInstanceOf(SignalTrap::class, $trap);
    }

    #[Test]
    public function defaultPolicyReflectsSwooleAvailability(): void
    {
        $policy = SignalPolicy::default();

        if (!extension_loaded('swoole')) {
            self::assertSame([], $policy->exitCodes());
            return;
        }

        self::assertNotSame([], $policy->exitCodes());
    }

    #[Test]
    public function installWithEmptyPolicyIsANoop(): void
    {
        $trap = SignalTrap::install(SignalPolicy::disabled(), static function (): void {
            self::fail('No callback should fire for an empty policy.');
        });
        $trap->restore();

        self::assertInstanceOf(SignalTrap::class, $trap);
    }

    #[Test]
    public function restoreOnUninstalledTrapIsIdempotent(): void
    {
        $trap = SignalTrap::install(SignalPolicy::disabled(), static fn() => null);
        $trap->restore();
        $trap->restore();

        self::assertInstanceOf(SignalTrap::class, $trap);
    }
}
