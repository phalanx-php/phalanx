<?php

declare(strict_types=1);

namespace Phalanx\Archon\Command;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Support\ExecutionScopeDelegate;

class ExecutionContext implements CommandContext
{
    use ExecutionScopeDelegate;

    public function __construct(
        private readonly ExecutionScope $inner,
        private(set) string $commandName,
        private(set) CommandArgs $args,
        private(set) CommandOptions $options,
        private(set) CommandConfig $config,
        private(set) string $commandResourceId,
    ) {
    }

    /** @param list<string> $rawArgs */
    public static function fromInput(
        ExecutionScope $scope,
        string $name,
        CommandConfig $config,
        array $rawArgs,
        string $resourceId,
    ): self {
        $input = ArgvParser::parse($rawArgs, $config);

        return new self(
            $scope,
            $name,
            $input->args,
            $input->options,
            $config,
            $resourceId,
        );
    }

    protected function innerScope(): ExecutionScope
    {
        return $this->inner;
    }
}
