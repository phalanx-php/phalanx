<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tool;

final readonly class ToolCallBag
{
    /** @param list<ToolCall> $calls */
    public function __construct(
        private array $calls = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->calls === [];
    }

    public function count(): int
    {
        return count($this->calls);
    }

    /**
     * @template T
     * @param callable(ToolCall): T $fn
     * @return list<T>
     */
    public function map(callable $fn): array
    {
        return array_map($fn, $this->calls);
    }

    /** @return list<ToolCall> */
    public function all(): array
    {
        return $this->calls;
    }

    public function get(int $index): ToolCall
    {
        return $this->calls[$index];
    }
}
