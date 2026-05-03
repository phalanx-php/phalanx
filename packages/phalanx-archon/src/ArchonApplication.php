<?php

declare(strict_types=1);

namespace Phalanx\Archon;

use Phalanx\AppHost;

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

    /** @param list<string>|null $argv */
    public function run(?array $argv = null): int
    {
        return (int) $this->host->run(new ArchonDispatchTask(
            application: $this,
            argv: array_values($argv ?? $this->consoleConfig->argv),
        ));
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
