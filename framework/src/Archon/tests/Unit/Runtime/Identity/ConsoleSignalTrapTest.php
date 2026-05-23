<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Unit\Runtime\Identity;

use Phalanx\Archon\Runtime\Identity\ConsoleSignalPolicy;
use Phalanx\Archon\Runtime\Identity\ConsoleSignalTrap;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConsoleSignalTrapTest extends TestCase
{
    #[Test]
    public function disabledTrapIsANoop(): void
    {
        $trap = ConsoleSignalTrap::install(ConsoleSignalPolicy::disabled(), static function (): void {
            self::fail('Disabled signal trap should not register callbacks.');
        });

        $trap->restore();

        self::assertInstanceOf(ConsoleSignalTrap::class, $trap);
    }

    #[Test]
    public function defaultPolicyReflectsOpenSwooleAvailability(): void
    {
        $policy = ConsoleSignalPolicy::default();

        if (!extension_loaded('openswoole')) {
            self::assertSame([], $policy->exitCodes());
            return;
        }

        self::assertNotSame([], $policy->exitCodes());
    }

    #[Test]
    public function installWithEmptyPolicyIsANoop(): void
    {
        $trap = ConsoleSignalTrap::install(ConsoleSignalPolicy::disabled(), static function (): void {
            self::fail('No callback should fire for an empty policy.');
        });
        $trap->restore();

        self::assertInstanceOf(ConsoleSignalTrap::class, $trap);
    }

    #[Test]
    public function restoreOnUninstalledTrapIsIdempotent(): void
    {
        $trap = ConsoleSignalTrap::install(ConsoleSignalPolicy::disabled(), static fn() => null);
        $trap->restore();
        $trap->restore();

        self::assertInstanceOf(ConsoleSignalTrap::class, $trap);
    }
}
