<?php

declare(strict_types=1);

use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarnessRunner;
use Phalanx\Boot\Optional;
use Phalanx\Redis\RedisServiceBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedisServiceBundleHarnessTest extends TestCase
{
    #[Test]
    public function harnessIsNonEmpty(): void
    {
        $harness = RedisServiceBundle::harness();

        self::assertFalse($harness->isEmpty());
    }

    #[Test]
    public function harnessDeclaresOneOptionalEnvKey(): void
    {
        $harness = RedisServiceBundle::harness();
        $requirements = $harness->all();

        self::assertCount(1, $requirements);
        self::assertSame(Optional::KIND_ENV, $requirements[0]->kind);
    }

    #[Test]
    public function evaluationPassesWithRedisUrlPresent(): void
    {
        $context = AppContext::test(['REDIS_URL' => 'redis://127.0.0.1:6379']);

        $report = (new BootHarnessRunner())->run($context, [RedisServiceBundle::class], vendorDir: null);

        self::assertFalse($report->hasFailures());
        self::assertFalse($report->hasWarnings());
    }

    #[Test]
    public function evaluationWarnsWithoutRedisUrlButDoesNotFail(): void
    {
        $context = AppContext::test([]);

        $report = (new BootHarnessRunner())->run($context, [RedisServiceBundle::class], vendorDir: null);

        self::assertFalse($report->hasFailures());
        self::assertTrue($report->hasWarnings());
        self::assertCount(1, $report->warned);
    }
}
