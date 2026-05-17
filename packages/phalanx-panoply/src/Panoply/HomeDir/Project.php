<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir;

/**
 * A single project entry inside a tool's home directory — e.g. one
 * `~/.claude/projects/<slug>/` folder or one entry in `~/.gemini/
 * projects.json`. The `slug` is tool-specific; the `path` is always the
 * canonical filesystem path it refers to.
 */
final class Project
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private(set) string $slug,
        private(set) string $path,
        private(set) string $homeDirId,
        private(set) ?\DateTimeImmutable $lastActive = null,
        private(set) int $conversationCount = 0,
        private(set) array $metadata = [],
    ) {
    }

    public function exists(): bool
    {
        return is_dir($this->path);
    }
}
