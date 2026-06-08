<?php

declare(strict_types=1);

namespace Phalanx\Console\Application;

use Phalanx\AppHost;
use Phalanx\Console\Command\CommandDispatcher;
use Phalanx\Console\Command\CommandGroup;
use Phalanx\Console\Command\DispatchTask;
use Phalanx\Console\Command\InlineCommand;
use Phalanx\Console\Runtime\SignalState;
use Phalanx\Scope\ExecutionScope;

final class Application
{
    /**
     * @param array<string, InlineCommand> $inlineCommands
     * @param list<\Phalanx\Console\ErrorRenderer> $errorRenderers
     */
    public function __construct(
        private readonly AppHost $host,
        private readonly CommandGroup $commands,
        private readonly Config $config,
        private readonly array $inlineCommands = [],
        private readonly array $errorRenderers = [],
    ) {
    }

    public function runtime(): AppHost
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

    public function config(): Config
    {
        return $this->config;
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
    public function dispatchScoped(ExecutionScope $scope, array $argv, ?SignalState $signals = null): int
    {
        return $this->dispatcher()->dispatchScoped($scope, array_values($argv), $signals);
    }

    /** @param list<string>|null $argv */
    public function run(?array $argv = null): int
    {
        $signals = new SignalState();

        return (int) $this->host->run(new DispatchTask(
            application: $this,
            argv: array_values($argv ?? $this->config->argv),
            signals: $signals,
            signalPolicy: $this->config->signalPolicy(),
        ));
    }

    public function shutdown(): void
    {
        $this->host->shutdown();
    }

    private function dispatcher(): CommandDispatcher
    {
        return (new CommandDispatcher(
            host: $this->host,
            commands: $this->commands,
            config: $this->config,
            inlineCommands: $this->inlineCommands,
        ))->withErrorRenderers(...$this->errorRenderers);
    }
}
