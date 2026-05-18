<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tool;

use Phalanx\Athena\Effect\Context as EffectContext;
use Phalanx\Athena\Effect\Outcome as EffectOutcome;
use Phalanx\Scope\TaskScope;

final class ToolRegistry
{
    /** @var array<string, class-string<Tool>> */
    private array $tools = [];

    public function merge(ToolBundle $bundle): static
    {
        $clone = clone $this;
        $clone->tools = [...$clone->tools, ...$bundle->tools];

        return $clone;
    }

    /** @param class-string<Tool> $toolClass */
    public function register(string $name, string $toolClass): void
    {
        $this->tools[$name] = $toolClass;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /** @return class-string<Tool>|null */
    public function find(string $name): ?string
    {
        return $this->tools[$name] ?? null;
    }

    /** @return list<array{name: string, description: string, parameters: array<string, mixed>}> */
    public function schema(): array
    {
        return array_values(array_map(
            static fn(string $name, string $class): array => ['name' => $name] + SchemaGenerator::forTool($class),
            array_keys($this->tools),
            array_values($this->tools),
        ));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function invoke(TaskScope $scope, string $name, EffectContext $ctx, array $arguments = []): EffectOutcome
    {
        $class = $this->tools[$name] ?? throw new \RuntimeException("Unknown tool: {$name}");

        $hydrated = ArgumentHydrator::hydrate($arguments, $class);
        $tool = new $class(...$hydrated);

        return $tool($ctx, $scope);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->tools);
    }
}
