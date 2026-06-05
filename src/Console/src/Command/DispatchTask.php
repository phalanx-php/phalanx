<?php

declare(strict_types=1);

namespace Phalanx\Console\Command;

use Phalanx\Console\Application\Application;
use Phalanx\Console\Runtime\Identity\Signal;
use Phalanx\Console\Runtime\Identity\SignalPolicy;
use Phalanx\Console\Runtime\Identity\SignalState;
use Phalanx\Console\Runtime\Identity\SignalTrap;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Traceable;

/**
 * Inside-the-coroutine dispatch entry. Installs the console signal trap from
 * here (rather than from Application::run) so Process::signal binds
 * inside the Swoole reactor that AppHost::run has already booted —
 * eager registration outside the reactor stalls the test process.
 *
 * @internal
 */
final class DispatchTask implements Executable, Traceable
{
    public string $traceName {
        get => 'console.application.run';
    }

    /** @param list<string> $argv */
    public function __construct(
        private Application $application,
        private array $argv,
        private ?SignalState $signals = null,
        private ?SignalPolicy $signalPolicy = null,
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

    private function installSignalTrap(ExecutionScope $scope): ?SignalTrap
    {
        if ($this->signalPolicy === null || $this->signalPolicy->exitCodes() === []) {
            return null;
        }

        $signals = $this->signals;
        $token = $scope->cancellation();

        return SignalTrap::install(
            $this->signalPolicy,
            static function (Signal $signal) use ($signals, $token): void {
                $signals?->record($signal);
                $token->cancel();
            },
        );
    }
}
