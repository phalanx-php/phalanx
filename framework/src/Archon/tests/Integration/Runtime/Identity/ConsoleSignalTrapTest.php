<?php

declare(strict_types=1);

namespace Phalanx\Archon\Tests\Integration\Runtime\Identity;

use OpenSwoole\Coroutine;
use OpenSwoole\Process;
use Phalanx\Archon\Runtime\Identity\ConsoleSignal;
use Phalanx\Archon\Runtime\Identity\ConsoleSignalPolicy;
use Phalanx\Archon\Runtime\Identity\ConsoleSignalTrap;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ConsoleSignalTrapTest extends PhalanxTestCase
{
    #[Test]
    public function reactorDeliversInstalledSignalToHandlerThenRestoreRemovesIt(): void
    {
        if (!defined('SIGUSR2')) {
            self::markTestSkipped('SIGUSR2 not defined on this platform.');
        }

        $received = null;

        $this->scope->run(static function (ExecutionScope $_scope) use (&$received): void {
            $trap = ConsoleSignalTrap::install(
                ConsoleSignalPolicy::forSignals([SIGUSR2 => 140]),
                static function (ConsoleSignal $signal) use (&$received): void {
                    $received = $signal;
                },
            );

            Process::kill(getmypid(), SIGUSR2);
            Coroutine::usleep(50_000);

            $trap->restore();
        });

        self::assertNotNull($received);
        self::assertSame(SIGUSR2, $received->number);
        self::assertSame(140, $received->exitCode);
    }
}
