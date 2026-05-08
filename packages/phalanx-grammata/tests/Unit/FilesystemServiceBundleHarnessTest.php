<?php

declare(strict_types=1);

namespace Phalanx\Grammata\Tests\Unit;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarnessRunner;
use Phalanx\Boot\Optional;
use Phalanx\Grammata\FilesystemServiceBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilesystemServiceBundleHarnessTest extends TestCase
{
    #[Test]
    public function harnessIsNonEmpty(): void
    {
        $harness = FilesystemServiceBundle::harness();

        self::assertFalse($harness->isEmpty());
    }

    #[Test]
    public function harnessDeclaresOneOptionalEnvKey(): void
    {
        $harness = FilesystemServiceBundle::harness();
        $requirements = $harness->all();

        self::assertCount(1, $requirements);
        self::assertSame(Optional::KIND_ENV, $requirements[0]->kind);
    }

    #[Test]
    public function evaluationPassesWithMaxOpenPresent(): void
    {
        $context = new AppContext(['FILESYSTEM_MAX_OPEN' => '32']);

        $report = (new BootHarnessRunner())->run($context, [FilesystemServiceBundle::class], vendorDir: null);

        self::assertFalse($report->hasFailures());
        self::assertFalse($report->hasWarnings());
    }

    #[Test]
    public function evaluationWarnsWithoutMaxOpenButDoesNotFail(): void
    {
        $context = new AppContext([]);

        $report = (new BootHarnessRunner())->run($context, [FilesystemServiceBundle::class], vendorDir: null);

        self::assertFalse($report->hasFailures());
        self::assertTrue($report->hasWarnings());
        self::assertCount(1, $report->warned);
    }
}
