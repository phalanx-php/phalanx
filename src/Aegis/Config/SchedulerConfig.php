<?php

declare(strict_types=1);

namespace Phalanx\Config;

use Phalanx\Scheduling\TaskPriority;
use Phalanx\Themis\Config;
use Phalanx\Themis\Env;
use Phalanx\Themis\Issue;
use Phalanx\Themis\ValidationContext;

final class SchedulerConfig implements Config
{
    public bool $configured {
        get => true;
    }

    public function __construct(
        #[Env(key: 'scheduler.max_concurrency', description: 'Maximum concurrent tasks')]
        private(set) int $maxConcurrency = 64,

        #[Env(key: 'scheduler.default_priority', description: 'Default task priority (0 = normal)')]
        private(set) int $defaultPriorityValue = 0,

        #[Env(key: 'recovery.default_retry.attempts', description: 'Default retry attempt count')]
        private(set) int $defaultRetryAttempts = 3,

        #[Env(key: 'recovery.default_retry.attempt_timeout_ms', description: 'Per-attempt timeout in ms')]
        private(set) ?int $defaultRetryAttemptTimeoutMs = null,

        #[Env(key: 'recovery.default_retry.deadline_ms', description: 'Overall deadline in ms')]
        private(set) ?int $defaultRetryDeadlineMs = null,

        #[Env(key: 'recovery.default_retry.backoff', description: 'Backoff strategy: fixed, linear, exponential')]
        private(set) string $defaultRetryBackoff = 'exponential',

        #[Env(key: 'recovery.default_retry.backoff_base_ms', description: 'Backoff base delay in ms')]
        private(set) int $defaultRetryBackoffBaseMs = 100,

        #[Env(key: 'recovery.default_retry.backoff_max_ms', description: 'Backoff max delay in ms')]
        private(set) int $defaultRetryBackoffMaxMs = 30000,

        #[Env(key: 'recovery.default_retry.jitter_percent', description: 'Jitter percentage (0-100)')]
        private(set) int $defaultRetryJitterPercent = 10,

        #[Env(key: 'recovery.polling.interval_ms', description: 'Default polling interval in ms')]
        private(set) int $pollingIntervalMs = 250,

        #[Env(key: 'recovery.polling.deadline_ms', description: 'Default polling deadline in ms')]
        private(set) ?int $pollingDeadlineMs = null,

        #[Env(key: 'circuit.store', description: 'Circuit breaker store: in-process')]
        private(set) string $circuitStore = 'in-process',
    ) {
    }

    public TaskPriority $defaultPriority {
        get => TaskPriority::tryFrom($this->defaultPriorityValue) ?? TaskPriority::Normal;
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
