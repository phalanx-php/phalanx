<?php

declare(strict_types=1);

namespace Phalanx\Tests\Integration\Boot;

use Phalanx\Application;
use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarness;
use Phalanx\Boot\Exception\CannotBootException;
use Phalanx\Boot\Required;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

// Bundle that demands CRITICAL_KEY to be present.
final class CriticalKeyBundle extends ServiceBundle
{
    #[\Override]
    public static function harness(): BootHarness
    {
        return BootHarness::of(Required::env('CRITICAL_KEY'));
    }

    public function services(Services $services, AppContext $context): void {}
}

final class CannotBootRenderingTest extends PhalanxTestCase
{
    // -------------------------------------------------------------------------
    // 1. Missing required key → CannotBootException thrown on compile()
    // -------------------------------------------------------------------------

    #[Test]
    public function compilingWithMissingRequiredKeyThrowsCannotBootException(): void
    {
        $this->expectException(CannotBootException::class);

        Application::starting([])
            ->providers(new CriticalKeyBundle())
            ->compile();
    }

    // -------------------------------------------------------------------------
    // 2. Exception message contains the missing key and .env remediation hint
    // -------------------------------------------------------------------------

    #[Test]
    public function cannotBootExceptionMessageContainsKeyAndRemediation(): void
    {
        try {
            Application::starting([])
                ->providers(new CriticalKeyBundle())
                ->compile();

            self::fail('Expected CannotBootException was not thrown.');
        } catch (CannotBootException $e) {
            self::assertStringContainsString('CRITICAL_KEY', $e->getMessage());
            self::assertStringContainsString('.env', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // 3. CannotBootException exposes the report with at least one failed entry
    // -------------------------------------------------------------------------

    #[Test]
    public function cannotBootExceptionExposesMeaningfulReport(): void
    {
        try {
            Application::starting([])
                ->providers(new CriticalKeyBundle())
                ->compile();

            self::fail('Expected CannotBootException was not thrown.');
        } catch (CannotBootException $e) {
            $report = $e->report;

            self::assertTrue($report->hasFailures());
            self::assertFalse($report->isClean());

            $failedEntry = $report->failed[0];
            self::assertStringContainsString('CRITICAL_KEY', $failedEntry->evaluation->message);
            self::assertNotNull($failedEntry->evaluation->remediation);
            self::assertStringContainsString('.env', $failedEntry->evaluation->remediation);
        }
    }

    // -------------------------------------------------------------------------
    // 4. Counter-case: key present → boot is clean, no exception
    // -------------------------------------------------------------------------

    #[Test]
    public function compilingWithRequiredKeyPresentBootsCleanly(): void
    {
        $app = Application::starting(['CRITICAL_KEY' => 'some-value'])
            ->providers(new CriticalKeyBundle())
            ->compile();

        self::assertInstanceOf(\Phalanx\Application::class, $app);

        // Tidy up the application we compiled in this test.
        $app->shutdown();
    }

    // -------------------------------------------------------------------------
    // 5. lastBootReport() on ApplicationBuilder reflects the evaluation result
    // -------------------------------------------------------------------------

    #[Test]
    public function applicationBuilderExposesLastBootReport(): void
    {
        $builder = Application::starting(['CRITICAL_KEY' => 'value'])
            ->providers(new CriticalKeyBundle());

        self::assertNull($builder->lastBootReport(), 'Report is null before compile().');

        $app = $builder->compile();
        $report = $builder->lastBootReport();

        self::assertNotNull($report);
        self::assertTrue($report->isClean());

        $app->shutdown();
    }
}
