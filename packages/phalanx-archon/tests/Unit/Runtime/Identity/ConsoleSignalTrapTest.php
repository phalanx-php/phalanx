<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Unit\Runtime\Identity;

use Phalanx\Archon\Runtime\Identity\ConsoleSignal;
use Phalanx\Archon\Runtime\Identity\ConsoleSignalPolicy;
use Phalanx\Archon\Runtime\Identity\ConsoleSignalTrap;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConsoleSignalTrapTest extends TestCase
{
    #[Test]
    public function trapRestoresPreviousHandlerAndAsyncSignalState(): void
    {
        if (
            !defined('SIGUSR2')
            || !function_exists('pcntl_signal')
            || !function_exists('pcntl_signal_get_handler')
            || !function_exists('pcntl_async_signals')
        ) {
            self::markTestSkipped('Signal trap restoration requires pcntl signal support.');
        }

        $previousAsync = pcntl_async_signals(false);
        $previousHandler = pcntl_signal_get_handler(SIGUSR2);
        $received = null;

        try {
            $trap = ConsoleSignalTrap::install(
                ConsoleSignalPolicy::forSignals([SIGUSR2 => 140]),
                static function (ConsoleSignal $signal) use (&$received): void {
                    $received = $signal;
                },
            );

            self::assertTrue(pcntl_async_signals());
            self::assertIsCallable(pcntl_signal_get_handler(SIGUSR2));

            $trap->restore();

            self::assertSame($previousHandler, pcntl_signal_get_handler(SIGUSR2));
            self::assertFalse(pcntl_async_signals());
            self::assertNull($received);
        } finally {
            pcntl_signal(SIGUSR2, $previousHandler);
            pcntl_async_signals($previousAsync);
        }
    }

    #[Test]
    public function disabledTrapIsANoop(): void
    {
        $trap = ConsoleSignalTrap::install(ConsoleSignalPolicy::disabled(), static function (): void {
            self::fail('Disabled signal trap should not register callbacks.');
        });

        $trap->restore();

        self::assertInstanceOf(ConsoleSignalTrap::class, $trap);
    }
}
