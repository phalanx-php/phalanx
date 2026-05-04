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
    public function installSkipsWhenOpenSwooleMissing(): void
    {
        if (extension_loaded('openswoole')) {
            self::markTestSkipped('OpenSwoole loaded — covered by reactor-driven test.');
        }

        $policy = ConsoleSignalPolicy::default();
        $trap = ConsoleSignalTrap::install($policy, static function (): void {
            self::fail('No callback should fire without OpenSwoole.');
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
