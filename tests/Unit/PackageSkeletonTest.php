<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit;

use Phalanx\Phalanx;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PackageSkeletonTest extends TestCase
{
    #[Test]
    public function composerMetadataDefinesTheMinimalV2PackageSkeleton(): void
    {
        $composer = $this->composerJson();

        self::assertSame('phalanx-php/phalanx', $this->composerValue($composer, 'name'));
        self::assertSame('library', $this->composerValue($composer, 'type'));
        self::assertSame('^8.4', $this->composerValue($composer, 'require', 'php'));
        self::assertSame('src/', $this->composerValue($composer, 'autoload', 'psr-4', 'Phalanx\\'));
        self::assertSame('tests/', $this->composerValue($composer, 'autoload-dev', 'psr-4', 'Phalanx\\Tests\\'));
    }

    #[Test]
    public function composerScriptsExposeTheRequiredLocalQualityGates(): void
    {
        $scripts = $this->composerValue($this->composerJson(), 'scripts');

        self::assertIsArray($scripts);

        foreach (['test', 'analyse', 'cs', 'cs:fixer', 'check'] as $script) {
            self::assertArrayHasKey($script, $scripts);
        }
    }

    #[Test]
    public function staticAnalysisGateRunsAtMaxLevelOverThePackageSurface(): void
    {
        $config = (string) file_get_contents(dirname(__DIR__, 2) . '/phpstan.neon');

        self::assertStringContainsString('level: max', $config);
        self::assertStringContainsString('- src', $config);
        self::assertStringContainsString('- tests', $config);
        self::assertStringNotContainsString('baseline', $config);
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/phpstan-baseline.neon');
    }

    #[Test]
    public function publicBootstrapMetadataRemainsTheOnlyCommittedFrameworkContract(): void
    {
        $composer = $this->composerJson();

        self::assertSame('2.0-dev', Phalanx::VERSION);
        self::assertSame(
            Phalanx::bootstrapContract()->toArray(),
            $this->composerValue($composer, 'extra', 'phalanx', 'bootstrap'),
        );
    }

    /** @return array<string, mixed> */
    private function composerJson(): array
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($composer);

        $normalized = [];

        foreach ($composer as $key => $value) {
            self::assertIsString($key);

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /** @param array<string, mixed> $composer */
    private function composerValue(array $composer, string ...$path): mixed
    {
        $value = $composer;

        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
