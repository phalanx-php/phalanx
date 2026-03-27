<?php

declare(strict_types=1);

namespace Phalanx\Console;

use Phalanx\AppHost;
use Phalanx\Handler\HandlerGroup;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use RuntimeException;

final class ConsoleRunner
{
    /**
     * @param array<string, Scopeable|Executable> $commands
     */
    public function __construct(
        private AppHost $app,
        private array $commands = [],
        private CommandGroup|HandlerGroup|null $handlers = null,
    ) {
    }

    public static function withHandlers(AppHost $app, CommandGroup|HandlerGroup $handlers): self
    {
        return new self($app, [], $handlers);
    }

    /** @param CommandGroup|string|list<string> $commands */
    public static function withCommands(AppHost $app, CommandGroup|string|array $commands): self
    {
        if (is_string($commands) || is_array($commands)) {
            $commands = self::loadFromPaths($app, $commands);
        }

        return new self($app, [], $commands);
    }

    public function withCommand(string $name, Scopeable|Executable $handler): self
    {
        return new self(
            $this->app,
            [...$this->commands, $name => $handler],
            $this->handlers,
        );
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $args = array_slice($argv, 2);

        if ($command === 'help') {
            if ($args !== [] && $this->handlers !== null) {
                return $this->showCommandHelp($args[0]);
            }

            return $this->showHelp();
        }

        if ($this->handlers !== null) {
            return $this->runWithHandlers($command, $args);
        }

        return $this->doRun($command, $args);
    }

    /** @param list<string> $args */
    private function runWithHandlers(string $command, array $args): int
    {
        assert($this->handlers !== null);

        $handlerGroup = $this->resolveHandlerGroup();
        $handler = $handlerGroup->get($command);

        if ($handler === null) {
            printf("Unknown command: %s\n", $command);
            $this->printAvailableCommands();
            return 1;
        }

        $this->app->startup();

        try {
            $scope = $this->app->createScope();
            $scope = $scope->withAttribute('command', $command);
            $scope = $scope->withAttribute('args', $args);

            $executable = $this->handlers instanceof HandlerGroup
                ? $this->handlers->withMatcher(new CommandMatcher())
                : $this->handlers;

            $result = $scope->execute($executable);

            $scope->dispose();

            return is_int($result) ? $result : 0;
        } catch (InvalidInputException $e) {
            printf("Error: %s\n\n", $e->getMessage());

            if ($e->config !== null) {
                echo HelpGenerator::forCommand($command, $e->config);
            }

            return 1;
        } catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'Command not found')) {
                printf("Unknown command: %s\n", $command);
                $this->printAvailableCommands();
                return 1;
            }

            printf("Error: %s\n", $e->getMessage());
            return 1;
        } catch (\Throwable $e) {
            printf("Error: %s\n", $e->getMessage());
            return 1;
        } finally {
            $this->app->shutdown();
        }
    }

    /** @param list<string> $args */
    private function doRun(string $command, array $args): int
    {
        if (!isset($this->commands[$command])) {
            printf("Unknown command: %s\n", $command);
            printf("Available: %s\n", implode(', ', array_keys($this->commands)));
            return 1;
        }

        $this->app->startup();

        try {
            $scope = $this->app->createScope();
            $scope = $scope->withAttribute('args', $args);

            $result = $scope->execute($this->commands[$command]);

            $scope->dispose();

            return is_int($result) ? $result : 0;
        } catch (\Throwable $e) {
            printf("Error: %s\n", $e->getMessage());
            return 1;
        } finally {
            $this->app->shutdown();
        }
    }

    private function showCommandHelp(string $name): int
    {
        assert($this->handlers !== null);

        $handlerGroup = $this->resolveHandlerGroup();
        $handler = $handlerGroup->get($name);

        if ($handler === null) {
            printf("Unknown command: %s\n", $name);
            $this->printAvailableCommands();

            return 1;
        }

        if ($handler->config instanceof CommandConfig) {
            echo HelpGenerator::forCommand($name, $handler->config);
        } else {
            printf("No help available for: %s\n", $name);
        }

        return 0;
    }

    private function showHelp(): int
    {
        echo "Available commands:\n\n";
        $this->printAvailableCommands();
        return 0;
    }

    private function resolveHandlerGroup(): HandlerGroup
    {
        assert($this->handlers !== null);

        return $this->handlers instanceof CommandGroup
            ? $this->handlers->handlers()
            : $this->handlers;
    }

    private function printAvailableCommands(): void
    {
        $commands = [];

        if ($this->handlers !== null) {
            $handlerGroup = $this->resolveHandlerGroup();

            foreach ($handlerGroup->filterByConfig(CommandConfig::class) as $name => $handler) {
                $desc = '';
                if ($handler->config instanceof CommandConfig) {
                    $desc = $handler->config->description;
                }
                $commands[$name] = $desc;
            }
        }

        foreach ($this->commands as $name => $_) {
            if (!isset($commands[$name])) {
                $commands[$name] = '';
            }
        }

        ksort($commands);

        $maxLen = max(array_map(strlen(...), array_keys($commands)) ?: [0]);

        foreach ($commands as $name => $desc) {
            $padding = str_repeat(' ', $maxLen - strlen($name) + 2);
            if ($desc !== '') {
                printf("  %s%s%s\n", $name, $padding, $desc);
            } else {
                printf("  %s\n", $name);
            }
        }
    }

    /** @param string|list<string> $paths */
    private static function loadFromPaths(AppHost $app, string|array $paths): CommandGroup
    {
        $paths = is_string($paths) ? [$paths] : $paths;
        $scope = $app->createScope();
        $group = CommandGroup::create();

        foreach ($paths as $dir) {
            $group = $group->merge(CommandLoader::loadDirectory($dir, $scope));
        }

        return $group;
    }
}
