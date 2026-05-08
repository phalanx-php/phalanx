<?php

declare(strict_types=1);

namespace Phalanx\Postgres\Tests\Unit;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarnessRunner;
use Phalanx\Boot\Optional;
use Phalanx\Postgres\PgServiceBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PgServiceBundleHarnessTest extends TestCase
{
    #[Test]
    public function harnessIsNonEmpty(): void
    {
        $harness = PgServiceBundle::harness();

        self::assertFalse($harness->isEmpty());
    }

    #[Test]
    public function harnessDeclaresFourOptionalEnvKeys(): void
    {
        $harness = PgServiceBundle::harness();
        $requirements = $harness->all();

        self::assertCount(4, $requirements);

        $kinds = array_map(static fn($r) => $r->kind, $requirements);
        self::assertSame(
            array_fill(0, 4, Optional::KIND_ENV),
            $kinds,
            'All four requirements must be Optional::env entries',
        );
    }

    #[Test]
    public function evaluationProducesNoFailuresWithDsnPresent(): void
    {
        $context = AppContext::test(['database_url' => 'pgsql://user:pass@localhost/mydb']);

        $report = (new BootHarnessRunner())->run($context, [PgServiceBundle::class], vendorDir: null);

        self::assertFalse($report->hasFailures());
    }

    #[Test]
    public function evaluationProducesNoFailuresWithSplitKeysPresent(): void
    {
        $context = AppContext::test([
            'pg_host' => 'postgres.local',
            'pg_port' => '5432',
            'pg_database' => 'my_app',
        ]);

        $report = (new BootHarnessRunner())->run($context, [PgServiceBundle::class], vendorDir: null);

        self::assertFalse($report->hasFailures());
    }

    #[Test]
    public function evaluationProducesWarningsNotFailuresWhenNoKeysPresent(): void
    {
        // Postgres config falls back to localhost defaults when no env is set;
        // absent Optional keys warn but never fail boot.
        $context = AppContext::test([]);

        $report = (new BootHarnessRunner())->run($context, [PgServiceBundle::class], vendorDir: null);

        self::assertFalse($report->hasFailures());
        self::assertTrue($report->hasWarnings());
    }
}
