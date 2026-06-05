<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Config;

use Phalanx\Config\SchedulerConfig;
use Phalanx\Recovery\BackoffStrategy;
use Phalanx\Scheduling\TaskPriority;
use Phalanx\Config\ValidationContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SchedulerConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreExplicit(): void
    {
        $config = new SchedulerConfig();

        self::assertSame(64, $config->maxConcurrency);
        self::assertSame(3, $config->defaultRetryAttempts);
        self::assertSame(100, $config->defaultRetryBackoffBaseMs);
        self::assertSame(30000, $config->defaultRetryBackoffMaxMs);
        self::assertSame(10, $config->defaultRetryJitterPercent);
        self::assertSame(250, $config->pollingIntervalMs);
        self::assertSame('in-process', $config->circuitStore);
        self::assertNull($config->defaultRetryAttemptTimeoutMs);
        self::assertNull($config->defaultRetryDeadlineMs);
        self::assertNull($config->pollingDeadlineMs);
    }

    #[Test]
    public function configuredAlwaysTrue(): void
    {
        self::assertTrue((new SchedulerConfig())->configured);
    }

    #[Test]
    public function defaultPriorityMapsFromInt(): void
    {
        self::assertSame(TaskPriority::Normal, (new SchedulerConfig())->defaultPriority);
        self::assertSame(TaskPriority::High, (new SchedulerConfig(defaultPriorityValue: 1))->defaultPriority);
        self::assertSame(TaskPriority::Low, (new SchedulerConfig(defaultPriorityValue: -1))->defaultPriority);
    }

    #[Test]
    public function invalidPriorityFallsBackToNormal(): void
    {
        self::assertSame(TaskPriority::Normal, (new SchedulerConfig(defaultPriorityValue: 99))->defaultPriority);
    }

    #[Test]
    public function backoffStrategyMapsFromString(): void
    {
        self::assertSame(BackoffStrategy::Exponential, (new SchedulerConfig())->backoffStrategy);
        self::assertSame(BackoffStrategy::Fixed, (new SchedulerConfig(defaultRetryBackoff: 'fixed'))->backoffStrategy);
        self::assertSame(BackoffStrategy::Linear, (new SchedulerConfig(defaultRetryBackoff: 'linear'))->backoffStrategy);
    }

    #[Test]
    public function unknownBackoffFallsBackToExponential(): void
    {
        self::assertSame(BackoffStrategy::Exponential, (new SchedulerConfig(defaultRetryBackoff: 'unknown'))->backoffStrategy);
    }

    #[Test]
    public function validateRejectsBadConcurrency(): void
    {
        $config = new SchedulerConfig(maxConcurrency: 0);

        $issues = $config->validate(new ValidationContext());

        self::assertCount(1, $issues);
        self::assertSame('scheduler.max_concurrency', $issues[0]->code);
    }

    #[Test]
    public function validateRejectsBadAttempts(): void
    {
        $config = new SchedulerConfig(defaultRetryAttempts: 0);

        $issues = $config->validate(new ValidationContext());

        self::assertCount(1, $issues);
        self::assertSame('recovery.default_retry.attempts', $issues[0]->code);
    }

    #[Test]
    public function validateRejectsJitterOutOfRange(): void
    {
        $config = new SchedulerConfig(defaultRetryJitterPercent: 101);

        $issues = $config->validate(new ValidationContext());

        self::assertCount(1, $issues);
        self::assertSame('recovery.default_retry.jitter_percent', $issues[0]->code);
    }

    #[Test]
    public function validateAcceptsJitterBoundaries(): void
    {
        self::assertSame([], (new SchedulerConfig(defaultRetryJitterPercent: 0))->validate(new ValidationContext()));
        self::assertSame([], (new SchedulerConfig(defaultRetryJitterPercent: 100))->validate(new ValidationContext()));
    }

    #[Test]
    public function validatePassesWithDefaults(): void
    {
        self::assertSame([], (new SchedulerConfig())->validate(new ValidationContext()));
    }
}
