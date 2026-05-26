<?php

declare(strict_types=1);

namespace Phalanx\Dory;

use Phalanx\Themis\Config;
use Phalanx\Themis\Env;
use Phalanx\Themis\Issue;
use Phalanx\Themis\IssueLevel;
use Phalanx\Themis\ValidationContext;

final class DoryConfig implements Config
{
    private const float DEFAULT_SCRIPT_TIMEOUT = 30.0;
    private const int DEFAULT_MAX_CONCURRENCY = 50;

    public bool $configured {
        get => $this->scriptTimeout > 0 && $this->maxConcurrency > 0;
    }

    public function __construct(
        #[Env(key: 'DORY_SCRIPT_TIMEOUT', description: 'Maximum script runtime in seconds')]
        private(set) float $scriptTimeout = self::DEFAULT_SCRIPT_TIMEOUT,
        #[Env(key: 'DORY_MAX_CONCURRENCY', description: 'Maximum concurrent tasks per script')]
        private(set) int $maxConcurrency = self::DEFAULT_MAX_CONCURRENCY,
        #[Env(key: 'DORY_VERBOSE', description: 'Enable verbose script output')]
        private(set) bool $verbose = false,
    ) {
    }

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array
    {
        $issues = [];

        if ($this->scriptTimeout <= 0) {
            $issues[] = new Issue(
                IssueLevel::Error,
                'dory.script-timeout',
                'DORY_SCRIPT_TIMEOUT must be greater than 0.',
                envKey: 'DORY_SCRIPT_TIMEOUT',
                path: 'scriptTimeout',
            );
        }

        if ($this->maxConcurrency < 1) {
            $issues[] = new Issue(
                IssueLevel::Error,
                'dory.max-concurrency',
                'DORY_MAX_CONCURRENCY must be at least 1.',
                envKey: 'DORY_MAX_CONCURRENCY',
                path: 'maxConcurrency',
            );
        }

        return $issues;
    }
}
