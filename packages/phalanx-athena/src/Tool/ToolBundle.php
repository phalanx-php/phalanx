<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tool;

final class ToolBundle
{
    /** @param array<string, class-string<Tool>> $tools name => class-string */
    public function __construct(private(set) array $tools = [])
    {
    }

    /** @param class-string<Tool> $toolClass */
    public function add(string $name, string $toolClass): self
    {
        $clone = clone $this;
        $clone->tools[$name] = $toolClass;

        return $clone;
    }
}
