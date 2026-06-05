<?php

declare(strict_types=1);

namespace Phalanx\Config;

final class ValidationReport
{
    /** Computed from contextual issue severity and strictness. */
    public bool $blocksBoot {
        get => $this->hasErrors || ($this->context->strict && $this->hasWarnings);
    }

    /** Computed from caller-owned validation issues. */
    public bool $hasErrors {
        get => $this->has(IssueLevel::Error);
    }

    /** Computed from caller-owned validation issues. */
    public bool $hasWarnings {
        get => $this->has(IssueLevel::Warning);
    }

    /** Computed convenience inverse of error presence. */
    public bool $valid {
        get => !$this->hasErrors;
    }

    /** @param list<Issue> $issues */
    public function __construct(
        private(set) Config $config,
        private(set) ValidationContext $context,
        private(set) array $issues,
    ) {
    }

    private function has(IssueLevel $level): bool
    {
        return array_any($this->issues, static fn(Issue $issue): bool => $issue->level === $level);
    }
}
