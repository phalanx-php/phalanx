<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Support;

final class ScopedRulePolicy
{
    /** @param list<string> $internalPaths */
    public function __construct(
        private readonly PathPolicy $paths,
        private readonly array $internalPaths = [],
    ) {
    }

    public function shouldReport(string $file): bool
    {
        if (!$this->paths->shouldReport($file)) {
            return false;
        }

        return !$this->paths->matches($file, $this->internalPaths);
    }
}
