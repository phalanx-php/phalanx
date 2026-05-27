<?php

declare(strict_types=1);

namespace Phalanx\DoryBin;

final class BuildOptions
{
    /** @param array<string, string> $env */
    public function __construct(
        private(set) BuildProfile $profile,

        private(set) ?string $outputPath = null,

        private(set) bool $clean = false,

        private(set) bool $dryRun = false,

        private(set) array $env = [],
    ) {
    }
}
