<?php

declare(strict_types=1);

namespace Phalanx\Console;

use Closure;
use Phalanx\Scope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

/**
 * CLI command handler as an invokable with fn + config.
 *
 * Commands are defined with a closure, Scopeable, or Executable that receives
 * ExecutionScope at dispatch time. File loading receives Scope; handler
 * execution receives ExecutionScope.
 */
final class Command implements Scopeable
{
    public private(set) CommandConfig $config;

    /**
     * @param list<CommandArgument> $args
     * @param list<CommandOption> $opts
     */
    public function __construct(
        public private(set) Closure|Scopeable|Executable $fn,
        string $desc = '',
        array $args = [],
        array $opts = [],
        CommandConfig|Closure|null $config = null,
    ) {
        if ($config !== null) {
            $this->config = $config instanceof Closure
                ? $config(new CommandConfig())
                : $config;
        } else {
            $this->config = new CommandConfig(
                description: $desc,
                arguments: $args,
                options: $opts,
            );
        }
    }

    public function __invoke(Scope $scope): mixed
    {
        return ($this->fn)($scope);
    }
}
