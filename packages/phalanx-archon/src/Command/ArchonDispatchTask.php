<?php

declare(strict_types=1);

namespace Phalanx\Archon\Command;

use Phalanx\Archon\Application\ArchonApplication;
use Phalanx\Archon\Runtime\Identity\ConsoleSignal;
use Phalanx\Archon\Runtime\Identity\ConsoleSignalPolicy;
use Phalanx\Archon\Runtime\Identity\ConsoleSignalState;
use Phalanx\Archon\Runtime\Identity\ConsoleSignalTrap;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Traceable;

/**
 * Inside-the-coroutine dispatch entry. Installs the console signal trap from
 * here (rather than from ArchonApplication::run) so Process::signal binds
 * inside the OpenSwoole reactor that AppHost::run has already booted —
 * eager registration outside the reactor stalls the test process.
 *
 * @internal
 */
final class ArchonDispatchTask implements Executable, Traceable
{
    public string $traceName {
        get => 'archon.application.run';
    }

    /** @param list<string> $argv */
    public function __construct(
        private ArchonApplication $application,
        private array $argv,
        private ?ConsoleSignalState $signals = null,
        private ?ConsoleSignalPolicy $signalPolicy = null,
    ) {
    }

    public function __invoke(ExecutionScope $scope): int
    {
        $trap = $this->installSignalTrap($scope);

        try {
            return $this->application->dispatchScoped($this->argv, $scope, $this->signals);
        } finally {
            $trap?->restore();
        }
    }

    private function installSignalTrap(ExecutionScope $scope): ?ConsoleSignalTrap
    {
        if ($this->signalPolicy === null || $this->signalPolicy->exitCodes() === []) {
            return null;
        }

        $signals = $this->signals;
        $token   = $scope->cancellation();

        return ConsoleSignalTrap::install(
            $this->signalPolicy,
            static function (ConsoleSignal $signal) use ($signals, $token): void {
                $signals?->record($signal);
                $token->cancel();
            },
        );
    }
}
