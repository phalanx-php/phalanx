<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Boot;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarness;
use Phalanx\Boot\BootHarnessRunner;
use Phalanx\Boot\CannotBootException;
use Phalanx\Boot\Optional;
use Phalanx\Boot\Required;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

// Stub bundle whose harness() returns a Required::env check.
final class RequiredEnvBundle extends ServiceBundle
{
    public static function harness(): BootHarness
    {
        return BootHarness::of(Required::env('CRITICAL_KEY'));
    }

    public function services(Services $services, AppContext $context): void {}
}

// Stub bundle whose harness() returns an Optional::env check.
final class OptionalEnvBundle extends ServiceBundle
{
    public static function harness(): BootHarness
    {
        return BootHarness::of(Optional::env('OPTIONAL_KEY'));
    }

    public function services(Services $services, AppContext $context): void {}
}

// Stub bundle with the default (empty) harness.
final class DefaultHarnessBundle extends ServiceBundle
{
    public function services(Services $services, AppContext $context): void {}
}

// Stub used by the composer-extra fallback test.
final class ComposerExtraHarnessStub
{
    public static function buildHarness(): BootHarness
    {
        return BootHarness::of(Required::env('FROM_EXTRA'));
    }
}

final class BootHarnessRunnerTest extends PhalanxTestCase
{
    // -------------------------------------------------------------------------
    // 1. No bundles + empty context → clean report
    // -------------------------------------------------------------------------

    #[Test]
    public function runWithNoBundlesAndEmptyContextIsClean(): void
    {
        $runner = new BootHarnessRunner();
        $report = $runner->run(AppContext::test([]), []);

        self::assertTrue($report->isClean());
        self::assertFalse($report->hasFailures());
        self::assertFalse($report->hasWarnings());
        self::assertSame([], $report->failed);
        self::assertSame([], $report->warned);
    }

    // -------------------------------------------------------------------------
    // 2. Bundle with Required::env + missing key → report.hasFailures() true
    // -------------------------------------------------------------------------

    #[Test]
    public function runWithMissingRequiredEnvProducesFailure(): void
    {
        $runner = new BootHarnessRunner();

        try {
            $runner->run(AppContext::test([]), [new RequiredEnvBundle()]);
            self::fail('Expected CannotBootException was not thrown.');
        } catch (CannotBootException $e) {
            $report = $e->report;

            self::assertTrue($report->hasFailures());
            self::assertFalse($report->hasWarnings());

            $failedEntry = $report->failed[0];
            self::assertStringContainsString('CRITICAL_KEY', $failedEntry->evaluation->message);
        }
    }

    // -------------------------------------------------------------------------
    // 3. Bundle with Optional::env + missing key → warn, not fail
    // -------------------------------------------------------------------------

    #[Test]
    public function runWithMissingOptionalEnvProducesWarning(): void
    {
        $runner = new BootHarnessRunner();
        $report = $runner->run(AppContext::test([]), [new OptionalEnvBundle()]);

        self::assertTrue($report->hasWarnings());
        self::assertFalse($report->hasFailures());
    }

    // -------------------------------------------------------------------------
    // 4. throwIfFailed() throws CannotBootException with key + remediation
    // -------------------------------------------------------------------------

    #[Test]
    public function throwIfFailedThrowsWithMeaningfulMessage(): void
    {
        $runner = new BootHarnessRunner();
        $report = $runner->dryRun(
            AppContext::test([]),
            BootHarness::of(Required::env('MISSING_KEY')),
        );

        $this->expectException(CannotBootException::class);
        $this->expectExceptionMessageMatches('/MISSING_KEY/');
        $this->expectExceptionMessageMatches('/\.env/');

        $runner->throwIfFailed($report);
    }

    // -------------------------------------------------------------------------
    // 5. dryRun() evaluates without bundle iteration
    // -------------------------------------------------------------------------

    #[Test]
    public function dryRunEvaluatesSuppliedHarnessDirectly(): void
    {
        $runner = new BootHarnessRunner();
        $harness = BootHarness::of(
            Required::env('A'),
            Optional::env('B'),
        );
        // A present, B absent.
        $report = $runner->dryRun(AppContext::test(['A' => 'value']), $harness);

        self::assertFalse($report->hasFailures());
        self::assertTrue($report->hasWarnings());
        self::assertCount(1, $report->passed);
        self::assertCount(1, $report->warned);
    }

    // -------------------------------------------------------------------------
    // 6. Composer-extra fallback via fake installed.json
    // -------------------------------------------------------------------------

    #[Test]
    public function runPicksUpComposerExtraHarness(): void
    {
        $tmpDir = sys_get_temp_dir() . '/phalanx_harness_runner_' . bin2hex(random_bytes(4));
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
            $runner->run(AppContext::test([]), [], $tmpDir);
            self::fail('Expected CannotBootException for FROM_EXTRA was not thrown.');
        } catch (CannotBootException $e) {
            self::assertStringContainsString('FROM_EXTRA', $e->getMessage());
        } finally {
            unlink($tmpDir . '/composer/installed.json');
            rmdir($tmpDir . '/composer');
            rmdir($tmpDir);
        }
    }

    // -------------------------------------------------------------------------
    // 7. Default-harness bundles contribute nothing to the report
    // -------------------------------------------------------------------------

    #[Test]
    public function bundlesWithDefaultHarnessAreNoOps(): void
    {
        $runner = new BootHarnessRunner();
        $report = $runner->run(AppContext::test([]), [
            new DefaultHarnessBundle(),
            new DefaultHarnessBundle(),
        ]);

        self::assertTrue($report->isClean());
        self::assertSame([], $report->passed);
    }

    // -------------------------------------------------------------------------
    // Extra: class-string form of bundles is also accepted
    // -------------------------------------------------------------------------

    #[Test]
    public function runAcceptsBundleClassStrings(): void
    {
        $runner = new BootHarnessRunner();

        try {
            $runner->run(AppContext::test([]), [RequiredEnvBundle::class]);
            self::fail('Expected CannotBootException was not thrown.');
        } catch (CannotBootException $e) {
            self::assertStringContainsString('CRITICAL_KEY', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Extra: run() with all requirements satisfied returns clean report
    // -------------------------------------------------------------------------

    #[Test]
    public function runWithAllRequirementsSatisfiedReturnsCleanReport(): void
    {
        $runner = new BootHarnessRunner();
        $report = $runner->run(
            AppContext::test(['CRITICAL_KEY' => 'present', 'OPTIONAL_KEY' => 'also_present']),
            [new RequiredEnvBundle(), new OptionalEnvBundle()],
        );

        self::assertTrue($report->isClean());
        self::assertCount(2, $report->passed);
    }
}
