<?php

declare(strict_types=1);

namespace Sentinel\Agent;

use InvalidArgumentException;

final class Dossier
{
    public function __construct(
        public readonly string $name,
        public readonly string $lens,
        public readonly string $tagline,
        public readonly string $instructions,
        public readonly string $color,
    ) {}

    public string $glyph {
        get => ":{$this->name}.{$this->lens}>";
    }

    public static function fromFile(string $path, string $color): self
    {
        if (!file_exists($path)) {
            throw new InvalidArgumentException("Dossier not found: {$path}");
        }

        $content = file_get_contents($path);
        $name = self::extractName($path, $content);
        $lens = pathinfo($path, PATHINFO_FILENAME);
        $tagline = self::extractTagline($content);

        return new self($name, $lens, $tagline, trim($content), $color);
    }

    private static function extractName(string $path, string $content): string
    {
        if (preg_match('/^#\s+(.+)$/m', $content, $matches)) {
            return trim($matches[1]);
        }

        return ucfirst(pathinfo($path, PATHINFO_FILENAME));
    }

    private static function extractTagline(string $content): string
    {
        if (preg_match('/^>\s*(.+)$/m', $content, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }
}
