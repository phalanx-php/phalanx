<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir;

/**
 * Immutable value object representing a loaded HomeDir adapter configuration.
 * Reflects the YAML structure: a stable `id`, a human-readable `displayName`,
 * the filesystem `roots` that indicate whether the tool is installed, and the
 * fully-qualified `adapter` class that implements {@see \Phalanx\Panoply\HomeDir}.
 *
 * Final — subclassing would alter the shape and break config identity.
 */
final class Config
{
    /**
     * @param list<string>                                  $roots
     * @param class-string<\Phalanx\Panoply\HomeDir>        $adapter
     */
    public function __construct(
        private(set) string $id,
        private(set) string $displayName,
        private(set) array $roots,
        private(set) string $adapter,
    ) {
    }

    /**
     * @param list<string>                           $roots
     * @param class-string<\Phalanx\Panoply\HomeDir> $adapter
     */
    public static function of(
        string $id,
        string $displayName,
        array $roots,
        string $adapter,
    ): self {
        return new self(
            id: $id,
            displayName: $displayName,
            roots: $roots,
            adapter: $adapter,
        );
    }

    /**
     * Resolve a path token against the user's home directory. Handles `~/`
     * prefix substitution and the bare `~` token. All adapter `fromConfig()`
     * implementations share this logic.
     */
    public static function resolvePath(string $path, string $home): string
    {
        if (str_starts_with($path, '~/')) {
            return $home . substr($path, 1);
        }

        if ($path === '~') {
            return $home;
        }

        return $path;
    }
}
