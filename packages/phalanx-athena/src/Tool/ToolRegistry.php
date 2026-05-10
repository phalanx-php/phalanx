<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tool;

use Phalanx\Concurrency\RetryPolicy;

final class ToolRegistry
{
    /** @var array<string, class-string<Tool>> */
    private array $tools = [];

    /** @var array<string, array{name: string, description: string, input_schema: array<string, mixed>}> */
    private array $schemas = [];

    /** @param list<class-string<Tool>|ToolBundle> $tools */
    public static function from(array $tools): self
    {
        $registry = new self();

        foreach ($tools as $tool) {
            if ($tool instanceof ToolBundle) {
                foreach ($tool->tools() as $toolClass) {
                    $registry->register($toolClass);
                }
            } else {
                $registry->register($tool);
            }
        }

        return $registry;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function hydrate(ToolCall $call, ?string $hint = null): Tool
    {
        $class = $this->tools[$call->name]
            ?? throw new \InvalidArgumentException("Unknown tool: {$call->name}");

        $args = $call->arguments;

        if ($hint !== null) {
            $args['_retry_hint'] = $hint;
        }

        return new $class(...$args);
    }

    /** @return list<array{name: string, description: string, input_schema: array<string, mixed>}> */
    public function allSchemas(): array
    {
        return array_values($this->schemas);
    }

    public function retryPolicy(ToolCall $call): RetryPolicy
    {
        $class = $this->tools[$call->name] ?? null;

        if ($class !== null && is_subclass_of($class, \Phalanx\Task\Retryable::class)) {
            // Prefer a class-declared static default over instantiating an
            // uninitialized object (newInstanceWithoutConstructor fatals on hooked properties).
            if (method_exists($class, 'defaultRetryPolicy')) {
                return $class::defaultRetryPolicy();
            }

            return RetryPolicy::fixed(1, 0);
        }

        return RetryPolicy::fixed(1, 0);
    }

    /** @param class-string<Tool> $class */
    private function register(string $class): void
    {
        $schema = SchemaGenerator::generate($class);
        $name = $schema['name'];
        $this->tools[$name] = $class;
        $this->schemas[$name] = $schema;
    }
}
