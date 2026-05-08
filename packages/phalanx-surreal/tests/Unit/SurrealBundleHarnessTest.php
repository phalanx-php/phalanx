<?php

declare(strict_types=1);

use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarnessRunner;
use Phalanx\Boot\Optional;
use Phalanx\Surreal\SurrealBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SurrealBundleHarnessTest extends TestCase
{
    #[Test]
    public function harnessIsNonEmpty(): void
    {
        $harness = SurrealBundle::harness();

        self::assertFalse($harness->isEmpty());
    }

    #[Test]
    public function harnessDeclaresOptionalEnvKeys(): void
    {
        $harness = SurrealBundle::harness();
        $requirements = $harness->all();

        self::assertCount(6, $requirements);

        $kinds = array_map(static fn($r) => $r->kind, $requirements);
        self::assertSame(
            array_fill(0, 6, Optional::KIND_ENV),
            $kinds,
            'All six SurrealDB requirements must be Optional::env entries',
        );
    }

    #[Test]
    public function evaluationPassesWithFullConfigPresent(): void
    {
        $context = AppContext::test([
            'SURREAL_ENDPOINT' => 'http://127.0.0.1:8000',
            'SURREAL_NAMESPACE' => 'myns',
            'SURREAL_DATABASE' => 'mydb',
            'SURREAL_USERNAME' => 'root',
            'SURREAL_PASSWORD' => 'root',
            'SURREAL_TOKEN' => null,
        ]);

        // SURREAL_TOKEN is null → treated as absent → warns.
        $report = (new BootHarnessRunner())->run($context, [SurrealBundle::class], vendorDir: null);

        self::assertFalse($report->hasFailures());
    }

    #[Test]
    public function evaluationWarnsButDoesNotFailWithNoKeysPresent(): void
    {
        // SurrealDB has defaults for all keys — nothing is required at boot.
        $context = AppContext::test([]);

        $report = (new BootHarnessRunner())->run($context, [SurrealBundle::class], vendorDir: null);

        self::assertFalse($report->hasFailures());
        self::assertTrue($report->hasWarnings());
    }
}
