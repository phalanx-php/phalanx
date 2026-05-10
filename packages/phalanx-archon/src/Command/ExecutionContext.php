<?php

declare(strict_types=1);

namespace Phalanx\Archon\Command;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Support\ExecutionScopeDelegate;

/**
 * Concrete CommandScope. Composes a parent ExecutionScope (Aegis-supplied)
 * with command identity, parsed input, the originating CommandConfig, and
 * the managed `archon.command` resource id. ExecutionScopeDelegate forwards
 * scope/cancellation/task primitives to the inner scope so this class only
 * owns the command-specific surface.
 */
class ExecutionContext implements CommandScope
{
    use ExecutionScopeDelegate;

    public string $commandName {
        get => $this->name;
    }

    public CommandArgs $args {
        get => $this->parsedArgs;
    }

    public string $commandResourceId {
        get => $this->resourceId;
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
        private readonly string $resourceId,
    ) {
    }

    public static function fromScope(ExecutionScope $scope, string $name, CommandConfig $config): self
    {
        /** @var list<string> $rawArgs */
        $rawArgs = $scope->attribute('args', []);
        $input = ArgvParser::parse($rawArgs, $config);

        return new self(
            $scope,
            $name,
            $input->args,
            $input->options,
            $config,
            (string) $scope->attribute(CommandLifecycle::RESOURCE_ATTRIBUTE, ''),
        );
    }

    public function withAttribute(string $key, mixed $value): CommandScope
    {
        return new self(
            $this->inner->withAttribute($key, $value),
            $this->name,
            $this->parsedArgs,
            $this->parsedOptions,
            $this->commandConfig,
            $this->resourceId,
        );
    }

    protected function innerScope(): ExecutionScope
    {
        return $this->inner;
    }
}
