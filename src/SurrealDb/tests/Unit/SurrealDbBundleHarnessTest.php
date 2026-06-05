<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb\Tests\Unit;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarnessRunner;
use Phalanx\Boot\Optional;
use Phalanx\SurrealDb\SurrealDbBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SurrealDbBundleHarnessTest extends TestCase
{
    #[Test]
    public function harnessIsNonEmpty(): void
    {
        $harness = SurrealDbBundle::harness();

        self::assertFalse($harness->isEmpty());
    }

    #[Test]
    public function harnessDeclaresOptionalEnvKeys(): void
    {
        $harness = SurrealDbBundle::harness();
        $requirements = $harness->all();

        self::assertCount(10, $requirements);

        $kinds = array_map(static fn($r) => $r->kind, $requirements);
        self::assertSame(
            array_fill(0, 10, Optional::KIND_ENV),
            $kinds,
            'All SurrealDB requirements must be Optional::env entries',
        );
    }

    #[Test]
    public function contextSchemaListsSurrealDbConfigKeys(): void
    {
        $schema = SurrealDbBundle::contextSchema();
        $keys = array_map(static fn($key): string => $key->name, $schema->all());

        self::assertSame([
            'SURREAL_ENDPOINT',
            'SURREAL_WS_ENDPOINT',
            'SURREAL_NAMESPACE',
            'SURREAL_DATABASE',
            'SURREAL_USERNAME',
            'SURREAL_PASSWORD',
            'SURREAL_TOKEN',
            'SURREAL_CONNECT_TIMEOUT',
            'SURREAL_READ_TIMEOUT',
            'SURREAL_MAX_RESPONSE_BYTES',
        ], $keys);

        self::assertStringContainsString('SURREAL_ENDPOINT', $schema->render());
        self::assertStringContainsString(SurrealDbBundle::class, $schema->render());
    }

    #[Test]
    public function evaluationPassesWithFullConfigPresent(): void
    {
        $context = new AppContext([
            'SURREAL_ENDPOINT' => 'http://127.0.0.1:8000',
            'SURREAL_NAMESPACE' => 'myns',
            'SURREAL_DATABASE' => 'mydb',
            'SURREAL_USERNAME' => 'root',
            'SURREAL_PASSWORD' => 'root',
            'SURREAL_TOKEN' => null,
        ]);

        // SURREAL_TOKEN is null → treated as absent → warns.
        $report = (new BootHarnessRunner())->run($context, [SurrealDbBundle::class], vendorDir: null);

        self::assertFalse($report->hasFailures());
    }

    #[Test]
    public function evaluationWarnsButDoesNotFailWithNoKeysPresent(): void
    {
        // SurrealDB has defaults for all keys — nothing is required at boot.
        $context = new AppContext([]);

        $report = (new BootHarnessRunner())->run($context, [SurrealDbBundle::class], vendorDir: null);

        self::assertFalse($report->hasFailures());
        self::assertTrue($report->hasWarnings());
    }
}
