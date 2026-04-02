<?php

declare(strict_types=1);

namespace Phalanx\Console;

use Closure;
use Phalanx\Scope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

final class Command implements Scopeable
{
    public private(set) CommandConfig $config;

    /**
     * @param list<CommandArgument> $args
     * @param list<CommandOption> $opts
     * @param list<CommandValidator> $validators
     */
    public function __construct(
        public private(set) Closure|Scopeable|Executable $fn,
        string $desc = '',
        array $args = [],
        array $opts = [],
        array $validators = [],
    ) {
        $this->config = new CommandConfig(
            description: $desc,
            arguments: $args,
            options: $opts,
            validators: $validators,
        );
    }

    public function __invoke(Scope $scope): mixed
    {
        return ($this->fn)($scope);
    }
}
