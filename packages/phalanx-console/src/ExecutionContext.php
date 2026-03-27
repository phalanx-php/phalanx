<?php

declare(strict_types=1);

namespace Phalanx\Console;

use Phalanx\ExecutionScope;
use Phalanx\Support\ExecutionScopeDelegate;

final class ExecutionContext implements CommandScope
{
    use ExecutionScopeDelegate;

    public string $commandName {
        get => $this->name;
    }

    public CommandArgs $args {
        get => $this->parsedArgs;
    }

    public CommandOptions $options {
        get => $this->parsedOptions;
    }

    public CommandConfig $config {
        get => $this->commandConfig;
    }

    public function __construct(
        private readonly ExecutionScope $inner,
        private readonly string $name,
        private readonly CommandArgs $parsedArgs,
        private readonly CommandOptions $parsedOptions,
        private readonly CommandConfig $commandConfig,
    ) {
    }

    public function withAttribute(string $key, mixed $value): CommandScope
    {
        return new self(
            $this->inner->withAttribute($key, $value),
            $this->name,
            $this->parsedArgs,
            $this->parsedOptions,
            $this->commandConfig,
        );
    }

    protected function innerScope(): ExecutionScope
    {
        return $this->inner;
    }
}
