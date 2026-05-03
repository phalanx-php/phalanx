<?php

declare(strict_types=1);

namespace Phalanx\Archon;

use Phalanx\AppHost;
use Phalanx\Archon\Output\StreamOutput;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\ScopeIdentity;
use RuntimeException;
use Throwable;

/** @internal */
final class CommandDispatcher
{
    private ?StreamOutput $output = null;

    private ?StreamOutput $errorOutput = null;

    /** @param array<string, InlineCommand> $inlineCommands */
    public function __construct(
        private AppHost $host,
        private CommandGroup $commands,
        private ConsoleConfig $config,
        private array $inlineCommands = [],
    ) {
    }

    /** @param list<string> $argv */
    public function dispatch(array $argv): int
    {
        $argv = array_values($argv);
        $rootScope = $this->host->createScope();

        try {
            return $this->dispatchInScope($argv, $rootScope);
        } finally {
            $rootScope->dispose();
        }
    }

    /**
     * @internal
     * @param list<string> $argv
     */
    public function dispatchScoped(array $argv, ExecutionScope $rootScope, ?ConsoleSignalState $signals = null): int
    {
        return $this->dispatchInScope(array_values($argv), $rootScope, $signals);
    }

    /** @param list<string> $argv */
    private function dispatchInScope(array $argv, ExecutionScope $rootScope, ?ConsoleSignalState $signals = null): int
    {
        $defaultCommand = $argv === [];
        $command = $argv[0] ?? $this->config->defaultCommand;
        $args = array_slice($argv, 1);
        $displayName = $this->displayName($command, $args);

        if (!$rootScope instanceof ScopeIdentity) {
            throw new RuntimeException('Archon command scopes require Aegis scope identity.');
        }

        $lifecycle = CommandLifecycle::open(
            scope: $rootScope,
            name: $displayName,
            argumentCount: count($args),
            defaultCommand: $defaultCommand,
        );
        $scope = $rootScope
            ->withAttribute('args', $args)
            ->withAttribute('command', $command)
            ->withAttribute(CommandLifecycle::RESOURCE_ATTRIBUTE, $lifecycle->resourceId);

        try {
            $scope->throwIfCancelled();
            $code = $this->execute($scope, $lifecycle, $command, $args);
            $lifecycle->close($code);

            return $code;
        } catch (Cancelled $e) {
            $signal = $signals === null ? null : $signals->current();
            $reason = $signal instanceof ConsoleSignal ? $signal->reason : $e->getMessage();
            $exitCode = $signal instanceof ConsoleSignal ? $signal->exitCode : 130;

            $lifecycle->abort($reason, $exitCode);
            $this->errorOutput()->persist("Cancelled: $reason");

            return $exitCode;
        } catch (InvalidInputException $e) {
            $lifecycle->invalidInput($e->getMessage(), $e);
            $this->writeInvalidInput($displayName, $e);

            return 1;
        } catch (UnknownCommand $e) {
            $unknown = $command === 'help' ? $e->command : $displayName;

            $lifecycle->unknown($unknown);
            $this->writeUnknownCommand($unknown);

            return 1;
        } catch (Throwable $e) {
            $lifecycle->fail('exception', $e->getMessage(), $e);
            $this->errorOutput()->persist("Error: {$e->getMessage()}");

            return 1;
        }
    }

    /** @param list<string> $args */
    private function execute(
        ExecutionScope $scope,
        CommandLifecycle $lifecycle,
        string $command,
        array $args,
    ): int {
        if ($command === 'help') {
            $lifecycle->activate('archon.help');
            $this->writeHelp($args);

            return 0;
        }

        $helpTarget = $this->groupHelpTarget($command, $args);
        if ($helpTarget !== null) {
            [$name, $group] = $helpTarget;

            $lifecycle->activate("archon.group.$name.help");
            $this->output()->persist(HelpGenerator::forGroup($name, $group));
            return 0;
        }

        $commandHelpPath = $this->commandHelpPath($command, $args);
        if ($commandHelpPath !== null) {
            $name = implode(' ', $commandHelpPath);

            $lifecycle->activate("archon.command.$name.help");
            $this->writeHelp($commandHelpPath);
            return 0;
        }

        if (isset($this->inlineCommands[$command])) {
            $lifecycle->activate($this->inlineCommands[$command]->traceName);
            $result = $scope->execute($this->inlineCommands[$command]);

            return is_int($result) ? $result : 0;
        }

        if (!in_array($command, $this->commands->keys(), true)) {
            $lifecycle->activate('archon.unknown');
            throw UnknownCommand::named($command);
        }

        $lifecycle->activate("archon.command.$command");
        $result = $scope->execute($this->commands);

        return is_int($result) ? $result : 0;
    }

    /** @param list<string> $args */
    private function displayName(string $command, array $args): string
    {
        return implode(' ', $this->commandPath($command, $args));
    }

    /** @param list<string> $path */
    private function writeHelp(array $path): void
    {
        if ($path === []) {
            $this->output()->persist($this->topLevelHelp());
            return;
        }

        $name = array_shift($path);

        if ($this->commands->isGroup($name)) {
            $group = $this->commands->group($name);
            assert($group !== null);

            $this->writeGroupHelpPath([$name], $group, $path);
            return;
        }

        $handler = $this->commands->handlers()->get($name);
        if (
            $handler !== null
            && $handler->config instanceof CommandConfig
            && $this->isHelpSuffix($path)
        ) {
            $this->output()->persist(HelpGenerator::forCommand($name, $handler->config));
            return;
        }

        if (isset($this->inlineCommands[$name]) && $this->isHelpSuffix($path)) {
            $this->output()->persist(HelpGenerator::forCommand($name, $this->inlineCommands[$name]->config));
            return;
        }

        if ($path !== []) {
            throw UnknownCommand::named(implode(' ', [$name, ...$path]));
        }

        throw UnknownCommand::named($name);
    }

    /**
     * @param list<string> $prefix
     * @param list<string> $path
     */
    private function writeGroupHelpPath(array $prefix, CommandGroup $group, array $path): void
    {
        while (true) {
            $next = array_shift($path);

            if ($next === null || $next === 'help' || $next === '--help') {
                $this->output()->persist(HelpGenerator::forGroup(implode(' ', $prefix), $group));
                return;
            }

            $prefix[] = $next;
            $child = $group->group($next);

            if ($child !== null) {
                $group = $child;
                continue;
            }

            $handler = $group->handlers()->get($next);
            if (
                $handler !== null
                && $handler->config instanceof CommandConfig
                && $this->isHelpSuffix($path)
            ) {
                $this->output()->persist(HelpGenerator::forCommand(implode(' ', $prefix), $handler->config));
                return;
            }

            throw UnknownCommand::named(implode(' ', [...$prefix, ...$path]));
        }
    }

    /** @param list<string> $path */
    private function isHelpSuffix(array $path): bool
    {
        return $path === [] || $path === ['help'] || $path === ['--help'];
    }

    /**
     * @param list<string> $args
     * @return list<string>|null
     */
    private function commandHelpPath(string $command, array $args): ?array
    {
        if (
            $this->commands->handlers()->get($command) !== null
            || isset($this->inlineCommands[$command])
        ) {
            return $this->isExplicitHelpSuffix($args) ? [$command] : null;
        }

        $group = $this->commands->group($command);
        if ($group === null) {
            return null;
        }

        $path = [$command];

        while ($args !== []) {
            $next = array_shift($args);

            if ($next === 'help' || $next === '--help') {
                return null;
            }

            $path[] = $next;
            $handler = $group->handlers()->get($next);

            if ($handler !== null && $handler->config instanceof CommandConfig) {
                return $this->isExplicitHelpSuffix($args) ? $path : null;
            }

            $group = $group->group($next);
            if ($group === null) {
                return null;
            }
        }

        return null;
    }

    /** @param list<string> $path */
    private function isExplicitHelpSuffix(array $path): bool
    {
        return $path === ['help'] || $path === ['--help'];
    }

    private function writeInvalidInput(string $command, InvalidInputException $e): void
    {
        $message = "Error: {$e->getMessage()}";

        if ($e->config !== null) {
            $message .= "\n\n" . HelpGenerator::forCommand($command, $e->config);
        }

        $this->errorOutput()->persist($message);
    }

    private function writeUnknownCommand(string $command): void
    {
        $this->errorOutput()->persist("Unknown command: $command\n" . $this->availableCommands());
    }

    private function topLevelHelp(): string
    {
        if ($this->inlineCommands === []) {
            return HelpGenerator::forTopLevel($this->commands);
        }

        return "Available commands:\n\n" . $this->availableCommands();
    }

    private function availableCommands(): string
    {
        $commands = [];

        foreach ($this->commands->commands() as $name => $handler) {
            $commands[$name] = $handler->config instanceof CommandConfig
                ? $handler->config->description
                : '';
        }

        foreach ($this->commands->groups() as $name => $group) {
            $commands[$name] = $group->description();
        }

        foreach ($this->inlineCommands as $name => $command) {
            $commands[$name] = $command->config->description;
        }

        ksort($commands);
        $maxLen = max(array_map(strlen(...), array_keys($commands)) ?: [0]);
        $lines = [];

        foreach ($commands as $name => $description) {
            $padding = str_repeat(' ', $maxLen - strlen($name) + 2);
            $lines[] = $description === '' ? "  $name" : "  $name$padding$description";
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $args
     * @return list<string>
     */
    private function commandPath(string $command, array $args): array
    {
        $path = [$command];
        $group = $this->commands->group($command);

        while ($group !== null && isset($args[0]) && $args[0] !== 'help' && $args[0] !== '--help') {
            $next = $args[0];
            if (!in_array($next, $group->keys(), true)) {
                $path[] = $next;
                break;
            }

            $path[] = $next;
            $group = $group->group($next);
            $args = array_slice($args, 1);
        }

        return $path;
    }

    /**
     * @param list<string> $args
     * @return array{string, CommandGroup}|null
     */
    private function groupHelpTarget(string $command, array $args): ?array
    {
        $group = $this->commands->group($command);
        if ($group === null) {
            return null;
        }

        $path = [$command];
        while (true) {
            $next = $args[0] ?? null;
            if ($next === null || $next === 'help' || $next === '--help') {
                return [implode(' ', $path), $group];
            }

            $child = $group->group($next);
            if ($child === null) {
                return null;
            }

            $path[] = $next;
            $group = $child;
            $args = array_slice($args, 1);
        }
    }

    private function output(): StreamOutput
    {
        if ($this->output === null) {
            $this->output = $this->config->output
                ?? new StreamOutput(terminal: $this->config->terminal);
        }

        return $this->output;
    }

    private function errorOutput(): StreamOutput
    {
        if ($this->errorOutput === null) {
            $this->errorOutput = $this->config->errorOutput ?? $this->output();
        }

        return $this->errorOutput;
    }
}
