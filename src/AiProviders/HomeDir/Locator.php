<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\HomeDir;

/**
 * Final — canonical hash determinism: subclassing would alter toCanonical()
 * and break request-replay/audit stability across adapters.
 *
 * A single locator inside a tool's home directory — a file or directory
 * path yielded by {@see \Phalanx\AiProviders\HomeDir::locators()}. Carries
 * just enough metadata to decide whether to read it.
 */
final class Locator
{
    public function __construct(
        private(set) string $path,
        private(set) bool $isDirectory,
        private(set) ?int $sizeBytes = null,
        private(set) ?\DateTimeImmutable $modifiedAt = null,
    ) {
    }

    public function extension(): string
    {
        return pathinfo($this->path, PATHINFO_EXTENSION);
    }
}
