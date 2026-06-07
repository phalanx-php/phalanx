<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Integration\Boot;

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

        $this->testApp([], new CriticalKeyBundle());
    }

    // -------------------------------------------------------------------------
    // 2. Exception message contains the missing key and .env remediation hint
    // -------------------------------------------------------------------------

    #[Test]
    public function cannotBootExceptionMessageContainsKeyAndRemediation(): void
    {
        try {
            $this->testApp([], new CriticalKeyBundle());

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
            $this->testApp([], new CriticalKeyBundle());

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
        $app = $this->testApp(['CRITICAL_KEY' => 'some-value'], new CriticalKeyBundle());

        self::assertInstanceOf(\Phalanx\Application::class, $app->hostForInternalTesting());
    }

}
