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

        self::assertSame('phalanx-php/phalanx', $composer['name'] ?? null);
        self::assertSame('library', $composer['type'] ?? null);
        self::assertSame('^8.4', $composer['require']['php'] ?? null);
        self::assertSame('src/', $composer['autoload']['psr-4']['Phalanx\\'] ?? null);
        self::assertSame('tests/', $composer['autoload-dev']['psr-4']['Phalanx\\Tests\\'] ?? null);
    }

    #[Test]
    public function composerScriptsExposeTheRequiredLocalQualityGates(): void
    {
        $composer = $this->composerJson();

        foreach (['test', 'analyse', 'cs', 'cs:fixer', 'check'] as $script) {
            self::assertArrayHasKey($script, $composer['scripts'] ?? []);
        }
    }

    #[Test]
    public function publicBootstrapMetadataRemainsTheOnlyCommittedFrameworkContract(): void
    {
        $composer = $this->composerJson();

        self::assertSame('2.0-dev', Phalanx::VERSION);
        self::assertSame(Phalanx::bootstrapContract()->toArray(), $composer['extra']['phalanx']['bootstrap'] ?? null);
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

        return $composer;
    }
}
