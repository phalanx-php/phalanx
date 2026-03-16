<?php

declare(strict_types=1);

namespace Convoy\Console;

use Convoy\ExecutionScope;
use Convoy\Handler\Handler;
use Convoy\Handler\HandlerConfig;
use Convoy\Handler\HandlerGroup;
use Convoy\Task\Executable;
use Convoy\Task\Scopeable;

/**
 * Typed collection of CLI commands.
 *
 * Keys are command names.
 * Wraps HandlerGroup for dispatch mechanics.
 *
 * Accepts Command instances or any Scopeable/Executable with a public
 * CommandConfig $config property (the "one class = one command" convention).
 */
final class CommandGroup implements Executable
{
    private(set) HandlerGroup $inner;

    /** @param array<string, Command|Scopeable|Executable> $commands */
    private function __construct(array $commands)
    {
        $handlers = [];
        foreach ($commands as $name => $command) {
            $config = $command->config ?? new CommandConfig();
            assert($config instanceof HandlerConfig);
            $handlers[$name] = new Handler($command, $config);
        }
        $this->inner = HandlerGroup::of($handlers)->withMatcher(new CommandMatcher());
    }

    /** @param array<string, Command|Scopeable|Executable> $commands */
    public static function of(array $commands): self
    {
        return new self($commands);
    }

    public static function create(): self
    {
        return new self([]);
    }

    public static function fromHandlerGroup(HandlerGroup $inner): self
    {
        $instance = new self([]);
        $instance->inner = $inner->withMatcher(new CommandMatcher());

        return $instance;
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        return ($this->inner)($scope);
    }

    /**
     * Add a console command.
     */
    public function command(string $name, Scopeable|Executable $handler, string $description = ''): self
    {
        $newInner = $this->inner->add($name, new Handler($handler, new CommandConfig(description: $description)));

        return self::fromHandlerGroup($newInner);
    }

    public function merge(self $other): self
    {
        $newInner = $this->inner->merge($other->inner);

        return self::fromHandlerGroup($newInner);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return $this->inner->keys();
    }

    public function handlers(): HandlerGroup
    {
        return $this->inner;
    }

    /**
     * Get all command handlers.
     *
     * @return array<string, Handler>
     */
    public function commands(): array
    {
        return $this->inner->filterByConfig(CommandConfig::class);
    }
}
