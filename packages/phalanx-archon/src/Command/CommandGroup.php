<?php

declare(strict_types=1);

namespace Phalanx\Archon\Command;

use Phalanx\Handler\Handler;
use Phalanx\Handler\HandlerGroup;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use RuntimeException;

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
     * @param array<string, class-string<Scopeable|Executable>
     *     |array{class-string<Scopeable|Executable>, CommandConfig}
     *     |self> $commands
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

        $this->inner = HandlerGroup::of($handlers);
    }

    /**
     * @param array<string, class-string<Scopeable|Executable>
     *     |array{class-string<Scopeable|Executable>, CommandConfig}
     *     |self> $commands
     */
    public static function of(array $commands, string $description = ''): self
    {
        return new self($commands, $description);
    }

    /**
     * Wrap a raw HandlerGroup in a CommandGroup. Used by CommandLoader when a
     * command file returns a HandlerGroup rather than a CommandGroup directly.
     *
     * @internal
     */
    public static function fromHandlerGroup(HandlerGroup $inner, string $description = ''): self
    {
        $instance = new self([], $description);
        $instance->inner = $inner;

        return $instance;
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        throw new RuntimeException('CommandGroup requires CommandInvocation dispatch.');
    }

    /** @param list<string> $args */
    public function dispatch(ExecutionScope $scope, string $name, array $args, string $resourceId): mixed
    {
        if (isset($this->groups[$name])) {
            return $this->dispatchSubcommand($scope, $name, $this->groups[$name], $args, $resourceId);
        }

        $handler = $this->inner->get($name);

        if ($handler === null) {
            throw UnknownCommand::named($name);
        }

        assert($handler->config instanceof CommandConfig);

        return $this->inner->dispatch(
            $name,
            ExecutionContext::fromInput($scope, $name, $handler->config, $args, $resourceId),
        );
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

    /** @param list<string> $args */
    private function dispatchSubcommand(
        ExecutionScope $scope,
        string $name,
        self $group,
        array $args,
        string $resourceId,
    ): mixed
    {
        $subcommand = $args[0] ?? null;

        if ($subcommand === null || $subcommand === '--help' || $subcommand === 'help') {
            return HelpGenerator::forGroup($name, $group);
        }

        return $group->dispatch($scope, $subcommand, array_slice($args, 1), $resourceId);
    }
}
