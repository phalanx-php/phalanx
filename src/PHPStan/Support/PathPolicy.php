<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Support;

final class PathPolicy
{
    /**
     * @param list<string> $internalPaths
     * @param list<string> $enforcedPaths
     * @param list<string> $auditPaths
     */
    public function __construct(
        private readonly array $internalPaths = [],
        private readonly array $enforcedPaths = [],
        private readonly array $auditPaths = [],
    ) {
    }

    public function isInternal(string $file): bool
    {
        return $this->matchesAny($file, $this->internalPaths);
    }

    public function shouldReport(string $file): bool
    {
        if ($this->enforcedPaths === [] && $this->auditPaths === []) {
            return true;
        }

        return $this->matchesAny($file, $this->enforcedPaths)
            || $this->matchesAny($file, $this->auditPaths);
    }

    /** @param list<string> $paths */
    public function matches(string $file, array $paths): bool
    {
        return $this->matchesAny($file, $paths);
    }

    private static function normalize(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }

    /**
     * @param list<string> $paths
     */
    private function matchesAny(string $file, array $paths): bool
    {
        $normalizedFile = self::normalize($file);

        foreach ($paths as $path) {
            $normalizedPath = self::normalize($path);
            if ($normalizedPath === '') {
                continue;
            }

            if ($normalizedFile === $normalizedPath) {
                return true;
            }

            if (str_starts_with($normalizedFile, rtrim($normalizedPath, '/') . '/')) {
                return true;
            }

            if (str_ends_with($normalizedFile, '/' . $normalizedPath)) {
                return true;
            }

            if (str_contains($normalizedFile, '/' . rtrim($normalizedPath, '/') . '/')) {
                return true;
            }
        }

        return false;
    }
}
