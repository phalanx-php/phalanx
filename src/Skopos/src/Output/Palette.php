<?php

declare(strict_types=1);

namespace Phalanx\Skopos\Output;

final class Palette
{
    /** @var list<string> */
    private const array COLORS = [
        "\033[36m",  // cyan
        "\033[32m",  // green
        "\033[35m",  // magenta
        "\033[33m",  // yellow
        "\033[34m",  // blue
        "\033[96m",  // bright cyan
        "\033[92m",  // bright green
        "\033[95m",  // bright magenta
        "\033[93m",  // bright yellow
        "\033[94m",  // bright blue
    ];

    private const string RESET = "\033[0m";
    private const string DIM_RED = "\033[2;31m";

    /** @var array<string, string> */
    private array $assigned = [];

    private int $cursor = 0;

    public function colorFor(string $name): string
    {
        if (!isset($this->assigned[$name])) {
            $this->assigned[$name] = self::COLORS[$this->cursor % count(self::COLORS)];
            $this->cursor++;
        }

        return $this->assigned[$name];
    }

    public function reset(): string
    {
        return self::RESET;
    }

    public function stderrPrefix(): string
    {
        return self::DIM_RED;
    }
}
