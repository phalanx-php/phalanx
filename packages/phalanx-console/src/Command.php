<?php

declare(strict_types=1);

namespace Phalanx\Console;

use Closure;
use Phalanx\Scope;
use Phalanx\Task\Scopeable;

/**
 * CLI command handler as an invokable with fn + config.
 *
 * Commands are defined with a closure that receives ExecutionScope at dispatch time.
 * File loading receives Scope; handler execution receives ExecutionScope.
 */
final class Command implements Scopeable
{
    public private(set) CommandConfig $config;

    public function __construct(
        public private(set) Closure $fn,
        CommandConfig|Closure $config = new CommandConfig(),
    ) {
        $this->config = $config instanceof Closure
            ? $config(new CommandConfig())
            : $config;
    }

    public function __invoke(Scope $scope): mixed
    {
        return ($this->fn)($scope);
    }
}
