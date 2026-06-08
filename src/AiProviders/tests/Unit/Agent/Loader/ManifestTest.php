<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\Agent\Loader;

use Phalanx\AiProviders\Agent\Loader\LoaderError;
use Phalanx\AiProviders\Agent\Loader\Manifest;
use Phalanx\AiProviders\Agent\Registry;
use Phalanx\Testing\UsesTempWorkspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins {@see Manifest} loader behavior: valid YAML manifest registers agents,
 * schema violations throw LoaderError, missing files throw LoaderError.
 */
final class ManifestTest extends TestCase
{
    use UsesTempWorkspace;

    #[Test]
    public function validManifestRegistersAllAgents(): void
    {
        $loader = new Manifest(self::fixtureManifest());
        $registry = $loader->load();

        self::assertInstanceOf(Registry::class, $registry);
        self::assertSame(2, $registry->all()->count());
        self::assertTrue($registry->has('hoplites'));
        self::assertTrue($registry->has('phalanx'));
    }

    #[Test]
    public function missingFileThrowsLoaderError(): void
    {
        $loader = new Manifest('/does/not/exist/manifest.yaml');

        $this->expectException(LoaderError::class);

        $loader->load();
    }

    #[Test]
    public function missingFileErrorMessageContainsManifestNotFoundAndNotAgentClass(): void
    {
        $loader = new Manifest('/does/not/exist/manifest.yaml');

        try {
            $loader->load();
            self::fail('Expected LoaderError to be thrown.');
        } catch (LoaderError $e) {
            self::assertStringContainsString('manifest file not found', $e->getMessage());
            self::assertStringNotContainsString('Agent class', $e->getMessage());
        }
    }

    #[Test]
    public function emptyAgentsListThrowsLoaderError(): void
    {
        $path = $this->writeTempManifest("agents: []\n");

        $this->expectException(LoaderError::class);
        $this->expectExceptionMessage('non-empty list');

        new Manifest($path)->load();
    }

    #[Test]
    public function missingAgentsKeyThrowsLoaderError(): void
    {
        $path = $this->writeTempManifest("other_key: value\n");

        $this->expectException(LoaderError::class);
        $this->expectExceptionMessage("missing required key 'agents'");

        new Manifest($path)->load();
    }

    #[Test]
    public function unknownTopLevelKeyThrowsLoaderError(): void
    {
        $path = $this->writeTempManifest(
            "agents:\n  - class: Phalanx\\AiProviders\\Tests\\Fixtures\\Agent\\Discovered\\HoplitesAgent\nfoo: bar\n"
        );

        $this->expectException(LoaderError::class);
        $this->expectExceptionMessage("unknown key 'foo'");

        new Manifest($path)->load();
    }

    #[Test]
    public function missingClassKeyThrowsLoaderError(): void
    {
        $path = $this->writeTempManifest("agents:\n  - name: missing\n");

        $this->expectException(LoaderError::class);
        $this->expectExceptionMessage("missing required key 'class'");

        new Manifest($path)->load();
    }

    #[Test]
    public function unknownAgentKeyThrowsLoaderError(): void
    {
        $path = $this->writeTempManifest(
            "agents:\n  - class: Phalanx\\AiProviders\\Tests\\Fixtures\\Agent\\Discovered\\HoplitesAgent\n    extra: oops\n"
        );

        $this->expectException(LoaderError::class);
        $this->expectExceptionMessage("unknown key 'extra'");

        new Manifest($path)->load();
    }

    #[Test]
    public function nonExistentClassThrowsLoaderError(): void
    {
        $path = $this->writeTempManifest("agents:\n  - class: App\\Does\\Not\\Exist\n");

        $this->expectException(LoaderError::class);

        new Manifest($path)->load();
    }

    #[Test]
    public function loadIsIdempotent(): void
    {
        $loader = new Manifest(self::fixtureManifest());

        $r1 = $loader->load();
        $r2 = $loader->load();

        self::assertSame(2, $r1->all()->count());
        self::assertSame(2, $r2->all()->count());
    }

    private static function fixtureManifest(): string
    {
        return dirname(__DIR__, 3) . '/Fixtures/Agent/manifest.yaml';
    }

    private function writeTempManifest(string $content): string
    {
        $path = $this->tempWorkspace('ai-providers-manifest-')->file('manifest.yaml', $content);
        $this->assertTrue(is_file($path));

        return $path;
    }
}
