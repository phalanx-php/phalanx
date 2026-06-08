<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Support;

final class TestingPathPolicy
{
    private const array DEFAULT_PATHS = [
        'tests/Integration',
        'tests/Feature',
        'tests/Acceptance',
        'tests/Smoke',
        'tests/Resilience',
    ];

    /** @var list<string> */
    private readonly array $paths;

    /**
     * @param list<string> $paths
     * @param list<string> $useTestAppExemptPaths
     * @param list<string> $useTestScopeExemptPaths
     * @param list<string> $noRawSleepExemptPaths
     * @param list<string> $lensRequiresBundleExemptPaths
     * @param list<string> $directTestAppApplicationExemptPaths
     * @param list<string> $noRawIoPaths
     * @param list<string> $noRawIoExemptPaths
     */
    public function __construct(
        array $paths = [],
        private readonly array $useTestAppExemptPaths = [],
        private readonly array $useTestScopeExemptPaths = [],
        private readonly array $noRawSleepExemptPaths = [],
        private readonly array $lensRequiresBundleExemptPaths = [],
        private readonly array $directTestAppApplicationExemptPaths = [],
        private readonly array $noRawIoPaths = [],
        private readonly array $noRawIoExemptPaths = [],
    ) {
        $this->paths = $paths === [] ? self::DEFAULT_PATHS : $paths;
    }

    public function shouldReport(string $file, string $identifier): bool
    {
        return self::matchesAny($file, $this->pathsFor($identifier))
            && !self::matchesAny($file, $this->exemptionsFor($identifier));
    }

    /** @param list<string> $paths */
    public function matches(string $file, array $paths): bool
    {
        return self::matchesAny($file, $paths);
    }

    private static function normalize(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }

    /** @param list<string> $paths */
    private static function matchesAny(string $file, array $paths): bool
    {
        $candidateFiles = self::candidateFiles($file);

        foreach ($paths as $path) {
            $normalizedPath = self::normalize($path);
            if ($normalizedPath === '') {
                continue;
            }

            foreach ($candidateFiles as $candidateFile) {
                if ($candidateFile === $normalizedPath) {
                    return true;
                }

                if (str_starts_with($candidateFile, rtrim($normalizedPath, '/') . '/')) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function candidateFiles(string $file): array
    {
        $normalizedFile = self::normalize($file);
        $candidates = [$normalizedFile];

        foreach (['/src/', '/tests/'] as $rootMarker) {
            $position = strrpos("/{$normalizedFile}", $rootMarker);
            if ($position === false) {
                continue;
            }

            $candidates[] = substr($normalizedFile, $position);
        }

        return array_values(array_unique($candidates));
    }

    /** @return list<string> */
    private function exemptionsFor(string $identifier): array
    {
        return match ($identifier) {
            'phalanx.testing.useTestApp' => $this->useTestAppExemptPaths,
            'phalanx.testing.useTestScope' => $this->useTestScopeExemptPaths,
            'phalanx.testing.noRawSleep' => $this->noRawSleepExemptPaths,
            'phalanx.testing.lensRequiresBundle' => $this->lensRequiresBundleExemptPaths,
            'phalanx.testing.directTestAppApplication' => $this->directTestAppApplicationExemptPaths,
            'phalanx.testing.noRawIo' => $this->noRawIoExemptPaths,
            default => [],
        };
    }

    /** @return list<string> */
    private function pathsFor(string $identifier): array
    {
        if ($identifier === 'phalanx.testing.noRawIo' && $this->noRawIoPaths !== []) {
            return $this->noRawIoPaths;
        }

        return $this->paths;
    }
}
