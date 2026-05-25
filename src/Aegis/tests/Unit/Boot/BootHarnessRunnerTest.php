<?php

declare(strict_types=1);

namespace Phalanx\Aegis\Tests\Unit\Boot;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarness;
use Phalanx\Boot\BootHarnessRunner;
use Phalanx\Boot\Exception\CannotBootException;
use Phalanx\Boot\Optional;
use Phalanx\Boot\Required;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class RequiredEnvBundle extends ServiceBundle
{
    #[\Override]
    public static function harness(): BootHarness
    {
        return BootHarness::of(Required::env('CRITICAL_KEY'));
    }

    public function services(Services $services, AppContext $context): void {}
}

final class OptionalEnvBundle extends ServiceBundle
{
    #[\Override]
    public static function harness(): BootHarness
    {
        return BootHarness::of(Optional::env('OPTIONAL_KEY'));
    }

    public function services(Services $services, AppContext $context): void {}
}

final class DefaultHarnessBundle extends ServiceBundle
{
    public function services(Services $services, AppContext $context): void {}
}

final class ComposerExtraHarnessStub
{
    public static function buildHarness(): BootHarness
    {
        return BootHarness::of(Required::env('FROM_EXTRA'));
    }
}

final class BootHarnessRunnerTest extends PhalanxTestCase
{
    #[Test]
    public function runWithNoBundlesAndEmptyContextIsClean(): void
    {
        $runner = new BootHarnessRunner();
        $report = $runner->run(new AppContext([]), []);

        self::assertTrue($report->isClean());
        self::assertFalse($report->hasFailures());
        self::assertFalse($report->hasWarnings());
        self::assertSame([], $report->failed);
        self::assertSame([], $report->warned);
    }

    #[Test]
    public function runWithMissingRequiredEnvProducesFailure(): void
    {
        $runner = new BootHarnessRunner();

        try {
            $runner->run(new AppContext([]), [new RequiredEnvBundle()]);
            self::fail('Expected CannotBootException was not thrown.');
        } catch (CannotBootException $e) {
            $report = $e->report;

            self::assertTrue($report->hasFailures());
            self::assertFalse($report->hasWarnings());

            $failedEntry = $report->failed[0];
            self::assertStringContainsString('CRITICAL_KEY', $failedEntry->evaluation->message);
        }
    }

    #[Test]
    public function runWithMissingOptionalEnvProducesWarning(): void
    {
        $runner = new BootHarnessRunner();
        $report = $runner->run(new AppContext([]), [new OptionalEnvBundle()]);

        self::assertTrue($report->hasWarnings());
        self::assertFalse($report->hasFailures());
    }

    #[Test]
    public function throwIfFailedThrowsWithMeaningfulMessage(): void
    {
        $runner = new BootHarnessRunner();
        $report = $runner->dryRun(
            new AppContext([]),
            BootHarness::of(Required::env('MISSING_KEY')),
        );

        $this->expectException(CannotBootException::class);
        $this->expectExceptionMessageMatches('/MISSING_KEY/');
        $this->expectExceptionMessageMatches('/\.env/');

        $runner->throwIfFailed($report);
    }

    #[Test]
    public function dryRunEvaluatesSuppliedHarnessDirectly(): void
    {
        $runner = new BootHarnessRunner();
        $harness = BootHarness::of(
            Required::env('A'),
            Optional::env('B'),
        );
        $report = $runner->dryRun(new AppContext(['A' => 'value']), $harness);

        self::assertFalse($report->hasFailures());
        self::assertTrue($report->hasWarnings());
        self::assertCount(1, $report->passed);
        self::assertCount(1, $report->warned);
    }

    #[Test]
    public function runPicksUpComposerExtraHarness(): void
    {
        $tmpDir = sys_get_temp_dir() . '/phalanx_harness_runner_' . uniqid('plx_');
        mkdir($tmpDir . '/composer', recursive: true);

        $stub = ComposerExtraHarnessStub::class;
        $installed = [
            'packages' => [
                [
                    'name' => 'test/pkg',
                    'extra' => [
                        'phalanx' => [
                            'harness' => $stub . '::buildHarness',
                        ],
                    ],
                ],
            ],
        ];
        file_put_contents(
            $tmpDir . '/composer/installed.json',
            json_encode($installed),
        );

        try {
            $runner = new BootHarnessRunner();
            $runner->run(new AppContext([]), [], $tmpDir);
            self::fail('Expected CannotBootException for FROM_EXTRA was not thrown.');
        } catch (CannotBootException $e) {
            self::assertStringContainsString('FROM_EXTRA', $e->getMessage());
        } finally {
            unlink($tmpDir . '/composer/installed.json');
            rmdir($tmpDir . '/composer');
            rmdir($tmpDir);
        }
    }

    #[Test]
    public function bundlesWithDefaultHarnessAreNoOps(): void
    {
        $runner = new BootHarnessRunner();
        $report = $runner->run(new AppContext([]), [
            new DefaultHarnessBundle(),
            new DefaultHarnessBundle(),
        ]);

        self::assertTrue($report->isClean());
        self::assertSame([], $report->passed);
    }

    #[Test]
    public function runAcceptsBundleClassStrings(): void
    {
        $runner = new BootHarnessRunner();

        try {
            $runner->run(new AppContext([]), [RequiredEnvBundle::class]);
            self::fail('Expected CannotBootException was not thrown.');
        } catch (CannotBootException $e) {
            self::assertStringContainsString('CRITICAL_KEY', $e->getMessage());
        }
    }

    #[Test]
    public function runWithAllRequirementsSatisfiedReturnsCleanReport(): void
    {
        $runner = new BootHarnessRunner();
        $report = $runner->run(
            new AppContext(['CRITICAL_KEY' => 'present', 'OPTIONAL_KEY' => 'also_present']),
            [new RequiredEnvBundle(), new OptionalEnvBundle()],
        );

        self::assertTrue($report->isClean());
        self::assertCount(2, $report->passed);
    }

    #[Test]
    public function contextSchemaListsBundleAndComposerExtraKeys(): void
    {
        $tmpDir = sys_get_temp_dir() . '/phalanx_harness_schema_' . uniqid('plx_', true);
        mkdir($tmpDir . '/composer', recursive: true);

        $stub = ComposerExtraHarnessStub::class;
        $installed = [
            'packages' => [
                [
                    'name' => 'test/pkg',
                    'extra' => [
                        'phalanx' => [
                            'harness' => $stub . '::buildHarness',
                        ],
                    ],
                ],
            ],
        ];
        file_put_contents(
            $tmpDir . '/composer/installed.json',
            json_encode($installed),
        );

        try {
            $schema = (new BootHarnessRunner())->contextSchema([RequiredEnvBundle::class], $tmpDir);
            $keys = $schema->all();

            self::assertCount(2, $keys);
            self::assertSame('CRITICAL_KEY', $keys[0]->name);
            self::assertSame(RequiredEnvBundle::class, $keys[0]->owner);
            self::assertSame('FROM_EXTRA', $keys[1]->name);
            self::assertSame('composer-extra', $keys[1]->owner);
            self::assertStringContainsString('CRITICAL_KEY', $schema->render());
            self::assertStringContainsString(RequiredEnvBundle::class, $schema->render());
        } finally {
            unlink($tmpDir . '/composer/installed.json');
            rmdir($tmpDir . '/composer');
            rmdir($tmpDir);
        }
    }
}
