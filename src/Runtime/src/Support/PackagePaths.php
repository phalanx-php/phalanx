<?php

declare(strict_types=1);

namespace Phalanx\Support;

final class PackagePaths
{
    /**
     * @return list<string>
     */
    public static function ancestorCandidates(string $anchor, string $relativePath, int $maxDepth = 8): array
    {
        $directory = is_file($anchor)
            ? dirname($anchor)
            : $anchor;

        $resolved = realpath($directory);
        if ($resolved !== false) {
            $directory = $resolved;
        }

        $relativePath = ltrim($relativePath, '/');
        $candidates = [];

        for ($depth = 0; $depth <= $maxDepth; $depth++) {
            $candidates[] = "{$directory}/{$relativePath}";

            $parent = dirname($directory);
            if ($parent === $directory) {
                break;
            }

            $directory = $parent;
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @param list<string> $candidates
     */
    public static function firstExistingDirectory(array $candidates): ?string
    {
        return array_find($candidates, static fn(string $path): bool => is_dir($path));
    }

    /**
     * @param list<string> $candidates
     */
    public static function firstExistingFile(array $candidates): ?string
    {
        return array_find($candidates, static fn(string $path): bool => is_file($path));
    }
}
