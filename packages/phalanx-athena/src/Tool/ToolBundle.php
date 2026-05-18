<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tool;

final class ToolBundle
{
    /** @var array<string, class-string<Tool>> */
    private(set) array $tools;

    /** @param array<string, class-string<Tool>> $tools name => class-string */
    public function __construct(array $tools = [])
    {
        $this->tools = $tools;
    }

    /** @param class-string<Tool> $toolClass */
    public function add(string $name, string $toolClass): self
    {
        $clone = clone $this;
        $clone->tools[$name] = $toolClass;

        return $clone;
    }
}
