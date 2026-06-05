<?php

declare(strict_types=1);

namespace Phalanx\Config;

final class ValidationResult
{
    public bool $blocksBoot {
        get => $this->hasErrors || ($this->context->strict && $this->hasWarnings);
    }

    public bool $hasErrors {
        get => $this->has(IssueLevel::Error);
    }

    public bool $hasWarnings {
        get => $this->has(IssueLevel::Warning);
    }

    public bool $valid {
        get => !$this->hasErrors;
    }

    /**
     * @param list<HydratedConfig> $configs
     * @param list<Issue> $issues
     */
    public function __construct(
        private(set) array $configs,
        private(set) ValidationContext $context,
        private(set) array $issues,
    ) {
    }

    private function has(IssueLevel $level): bool
    {
        return array_any($this->issues, static fn(Issue $issue): bool => $issue->level === $level);
    }
}
