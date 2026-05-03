<?php

declare(strict_types=1);

namespace Phalanx\Archon\Application;

use Phalanx\AppHost;
use Phalanx\Archon\Command\ArchonDispatchTask;
use Phalanx\Archon\Command\CommandDispatcher;
use Phalanx\Archon\Command\CommandGroup;
use Phalanx\Archon\Command\InlineCommand;
use Phalanx\Archon\Runtime\Identity\ConsoleSignal;
use Phalanx\Archon\Runtime\Identity\ConsoleSignalState;
use Phalanx\Archon\Runtime\Identity\ConsoleSignalTrap;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Scope\ExecutionScope;

final class ArchonApplication
{
    /** @param array<string, InlineCommand> $inlineCommands */
    public function __construct(
        private readonly AppHost $host,
        private readonly CommandGroup $commands,
        private readonly ConsoleConfig $consoleConfig,
        private readonly array $inlineCommands = [],
    ) {
    }

    public function aegis(): AppHost
    {
        return $this->host;
    }

    public function host(): AppHost
    {
        return $this->host;
    }

    public function commands(): CommandGroup
    {
        return $this->commands;
    }

    public function consoleConfig(): ConsoleConfig
    {
        return $this->consoleConfig;
    }

    /** @param list<string> $argv */
    public function dispatch(array $argv): int
    {
        $argv = array_values($argv);

        $this->host->startup();

        return $this->dispatcher()->dispatch($argv);
    }

    /**
     * @internal
     * @param list<string> $argv
     */
    public function dispatchScoped(array $argv, ExecutionScope $scope, ?ConsoleSignalState $signals = null): int
    {
        return $this->dispatcher()->dispatchScoped(array_values($argv), $scope, $signals);
    }

    /** @param list<string>|null $argv */
    public function run(?array $argv = null): int
    {
        $token = CancellationToken::create();
        $signals = new ConsoleSignalState();
        $trap = ConsoleSignalTrap::install(
            $this->consoleConfig->signalPolicy(),
            static function (ConsoleSignal $signal) use ($signals, $token): void {
                $signals->record($signal);
                $token->cancel();
            },
        );

        try {
            return (int) $this->host->run(new ArchonDispatchTask(
                application: $this,
                argv: array_values($argv ?? $this->consoleConfig->argv),
                signals: $signals,
            ), $token);
        } finally {
            $trap->restore();
        }
    }

    public function shutdown(): void
    {
        $this->host->shutdown();
    }

    private function dispatcher(): CommandDispatcher
    {
        return new CommandDispatcher(
            host: $this->host,
            commands: $this->commands,
            config: $this->consoleConfig,
            inlineCommands: $this->inlineCommands,
        );
    }
}
