<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Widget;

use Phalanx\Theatron\Surface\Surface;
use Phalanx\Theatron\Surface\Region;

/**
 * A fluid panel that uses only horizontal delimiters, making it
 * more resilient to terminal resizing than boxed containers.
 */
final readonly class FluidPanel
{
    public function __construct(
        private string $title,
        private string $delimiter = '─',
    ) {}

    /** @param list<string> $content */
    public function render(int $width, array $content): string
    {
        $rule = str_repeat($this->delimiter, $width);
        $titleLine = " " . strtoupper($this->title) . " ";
        $paddedTitle = str_pad($titleLine, $width, $this->delimiter, STR_PAD_BOTH);

        $lines = [
            $paddedTitle,
            ...$content,
            $rule,
        ];

        return implode("\n", $lines);
    }
}
