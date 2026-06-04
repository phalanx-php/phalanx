<?php

declare(strict_types=1);

namespace Phalanx\Config;

use Phalanx\Recovery\BackoffStrategy;
use Phalanx\Scheduling\TaskPriority;
use Phalanx\Themis\Config;
use Phalanx\Themis\Issue;
use Phalanx\Themis\ValidationContext;

final class SchedulerConfig implements Config
{
    public bool $configured {
        get => true;
    }

    public TaskPriority $defaultPriority {
        get => TaskPriority::tryFrom($this->defaultPriorityValue) ?? TaskPriority::Normal;
    }

    public BackoffStrategy $backoffStrategy {
        get => match ($this->defaultRetryBackoff) {
            'fixed' => BackoffStrategy::Fixed,
            'linear' => BackoffStrategy::Linear,
            default => BackoffStrategy::Exponential,
        };
    }

    public function __construct(
        private(set) int $maxConcurrency = 64,
        private(set) int $defaultPriorityValue = 0,
        private(set) int $defaultRetryAttempts = 3,
        private(set) ?int $defaultRetryAttemptTimeoutMs = null,
        private(set) ?int $defaultRetryDeadlineMs = null,
        private(set) string $defaultRetryBackoff = 'exponential',
        private(set) int $defaultRetryBackoffBaseMs = 100,
        private(set) int $defaultRetryBackoffMaxMs = 30000,
        private(set) int $defaultRetryJitterPercent = 10,
        private(set) int $pollingIntervalMs = 250,
        private(set) ?int $pollingDeadlineMs = null,
        private(set) string $circuitStore = 'in-process',
    ) {
    }

    public function validate(ValidationContext $context): array
    {
        $issues = [];

        if ($this->maxConcurrency < 1) {
            $issues[] = Issue::error('scheduler.max_concurrency', 'Must be >= 1');
        }

        if ($this->defaultRetryAttempts < 1) {
            $issues[] = Issue::error('recovery.default_retry.attempts', 'Must be >= 1');
        }

        if ($this->defaultRetryJitterPercent < 0 || $this->defaultRetryJitterPercent > 100) {
            $issues[] = Issue::error('recovery.default_retry.jitter_percent', 'Must be 0-100');
        }

        return $issues;
    }
}
