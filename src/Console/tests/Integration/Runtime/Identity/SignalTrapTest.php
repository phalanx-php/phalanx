<?php

declare(strict_types=1);

namespace Phalanx\Console\Tests\Integration\Runtime\Identity;

use Phalanx\Console\Runtime\Signal;
use Phalanx\Console\Runtime\SignalPolicy;
use Phalanx\Console\Runtime\SignalTrap;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;
use Swoole\Coroutine;
use Swoole\Process;

final class SignalTrapTest extends PhalanxTestCase
{
    #[Test]
    public function reactorDeliversInstalledSignalToHandlerThenRestoreRemovesIt(): void
    {
        if (!extension_loaded('swoole') || !defined('SIGUSR2')) {
            self::markTestSkipped('Signal integration requires ext-swoole and SIGUSR2.');
        }

        $received = null;

        $this->scope->run(static function (ExecutionScope $_scope) use (&$received): void {
            $trap = SignalTrap::install(
                SignalPolicy::forSignals([SIGUSR2 => 140]),
                static function (Signal $signal) use (&$received): void {
                    $received = $signal;
                },
            );

            $pid = getmypid();
            self::assertIsInt($pid);
            Process::kill($pid, SIGUSR2);
            Coroutine::sleep(0.05);

            $trap->restore();
        });

        self::assertNotNull($received);
        self::assertSame(SIGUSR2, $received->number);
        self::assertSame(140, $received->exitCode);
    }
}
