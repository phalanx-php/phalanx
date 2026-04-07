<?php

declare(strict_types=1);

namespace Phalanx\Console;

use Phalanx\ExecutionScope;
use Phalanx\Handler\Handler;
use Phalanx\Handler\HandlerGroup;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

/**
 * Typed collection of CLI commands.
 *
 * Each command entry is either:
 *  - a class-string of a Scopeable/Executable command class
 *  - a tuple [class-string, CommandConfig] when the command needs description,
 *    arguments, options, or validators
 *  - another CommandGroup, for nested subcommand groups
 *
 * Command instances are constructed at dispatch time via HandlerResolver,
 * with constructor-injected dependencies from the service container.
 */
final class CommandGroup implements Executable
{
    private(set) HandlerGroup $inner;

    /** @var array<string, self> */
    private array $groups = [];

    /**
     * @param array<string, class-string<Scopeable|Executable>|array{class-string<Scopeable|Executable>, CommandConfig}|self> $commands
     */
    private function __construct(array $commands, private string $description = '')
    {
        $handlers = [];

        foreach ($commands as $name => $command) {
            if ($command instanceof self) {
                $this->groups[$name] = $command;
                continue;
            }

            if (is_array($command)) {
                [$class, $config] = $command;
                $handlers[$name] = new Handler($class, $config);
                continue;
            }

            $handlers[$name] = new Handler($command, new CommandConfig());
        }

        $this->inner = HandlerGroup::of($handlers)->withMatcher(new CommandMatcher());
    }

    /**
     * @param array<string, class-string<Scopeable|Executable>|array{class-string<Scopeable|Executable>, CommandConfig}|self> $commands
     */
    public static function of(array $commands, string $description = ''): self
    {
        return new self($commands, $description);
    }

    /**
     * Wrap a raw HandlerGroup in a CommandGroup, applying the command matcher.
     * Used by CommandLoader when a command file returns a HandlerGroup rather
     * than a CommandGroup directly.
     *
     * @internal
     */
    public static function fromHandlerGroup(HandlerGroup $inner, string $description = ''): self
    {
        $instance = new self([], $description);
        $instance->inner = $inner->withMatcher(new CommandMatcher());

        return $instance;
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        $name = $scope->attribute('command');

        if ($name !== null && isset($this->groups[$name])) {
            /** @var list<string> $args */
            $args = $scope->attribute('args', []);
            $subcommand = $args[0] ?? null;

            if ($subcommand === null || $subcommand === '--help' || $subcommand === 'help') {
                echo HelpGenerator::forGroup($name, $this->groups[$name]);
                return 0;
            }

            $childScope = $scope
                ->withAttribute('command', $subcommand)
                ->withAttribute('args', array_slice($args, 1));

            return ($this->groups[$name])($childScope);
        }

        return ($this->inner)($scope);
    }

    public function merge(self $other): self
    {
        $newInner = $this->inner->merge($other->inner);
        $instance = self::fromHandlerGroup($newInner, $this->description);
        $instance->groups = [...$this->groups, ...$other->groups];

        return $instance;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return [...$this->inner->keys(), ...array_keys($this->groups)];
    }

    public function isGroup(string $name): bool
    {
        return isset($this->groups[$name]);
    }

    public function group(string $name): ?self
    {
        return $this->groups[$name] ?? null;
    }

    /** @return array<string, self> */
    public function groups(): array
    {
        return $this->groups;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function handlers(): HandlerGroup
    {
        return $this->inner;
    }

    /** @return array<string, Handler> */
    public function commands(): array
    {
        return $this->inner->filterByConfig(CommandConfig::class);
    }
}
