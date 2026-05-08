<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit;

use Phalanx\Athena\AiServiceBundle;
use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarnessRunner;
use Phalanx\Boot\Optional;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiServiceBundleHarnessTest extends TestCase
{
    #[Test]
    public function harnessIsNonEmpty(): void
    {
        $harness = AiServiceBundle::harness();

        self::assertFalse($harness->isEmpty());
    }

    #[Test]
    public function harnessDeclaresAllFiveProviderKeys(): void
    {
        $harness = AiServiceBundle::harness();
        $requirements = $harness->all();

        self::assertCount(5, $requirements);

        $kinds = array_map(static fn($r) => $r->kind, $requirements);
        self::assertSame(
            array_fill(0, 5, Optional::KIND_ENV),
            $kinds,
            'All five requirements must be Optional::env entries',
        );
    }

    #[Test]
    public function evaluationProducesNoFailuresWithAllKeysPresent(): void
    {
        $context = AppContext::test([
            'ANTHROPIC_API_KEY' => 'sk-ant-test',
            'OPENAI_API_KEY' => 'sk-openai-test',
            'GEMINI_API_KEY' => 'gemini-test',
            'OLLAMA_BASE_URL' => 'http://localhost:11434',
            'OLLAMA_MODEL' => 'llama3',
        ]);

        $report = (new BootHarnessRunner())->run($context, [AiServiceBundle::class], vendorDir: null);

        self::assertFalse($report->hasFailures());
        self::assertFalse($report->hasWarnings());
    }

    #[Test]
    public function evaluationProducesWarningsWithNoKeysPresent(): void
    {
        $context = AppContext::test([]);

        $report = (new BootHarnessRunner())->run($context, [AiServiceBundle::class], vendorDir: null);

        // Optional missing = warn, not fail — boot continues.
        self::assertFalse($report->hasFailures());
        self::assertTrue($report->hasWarnings());
        self::assertCount(5, $report->warned);
    }

    #[Test]
    public function evaluationProducesNoFailuresWhenOnlyOneProviderKeyPresent(): void
    {
        $context = AppContext::test(['ANTHROPIC_API_KEY' => 'sk-ant-test']);

        $report = (new BootHarnessRunner())->run($context, [AiServiceBundle::class], vendorDir: null);

        self::assertFalse($report->hasFailures());
        // Remaining four keys absent → four warnings.
        self::assertCount(4, $report->warned);
        self::assertCount(1, $report->passed);
    }
}
