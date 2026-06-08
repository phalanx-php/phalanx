<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\Agent\Loader;

use Phalanx\AiProviders\Agent\Loader\Cached;
use Phalanx\AiProviders\Agent\Loader\LoaderError;
use Phalanx\AiProviders\Agent\Registry;
use Phalanx\Testing\UsesTempWorkspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins {@see Cached} loader behavior: valid cache JSON registers agents,
 * missing or malformed files throw LoaderError, and isStale() reflects
 * source directory mtime vs cache source_mtime.
 */
final class CachedTest extends TestCase
{
    use UsesTempWorkspace;

    #[Test]
    public function validCacheRegistersAllAgents(): void
    {
        $loader = new Cached(self::fixtureCachePath());
        $registry = $loader->load();

        self::assertInstanceOf(Registry::class, $registry);
        self::assertSame(2, $registry->all()->count());
        self::assertTrue($registry->has('hoplites'));
        self::assertTrue($registry->has('phalanx'));
    }

    #[Test]
    public function missingCacheFileThrowsLoaderError(): void
    {
        $loader = new Cached('/does/not/exist/cache.json');

        $this->expectException(LoaderError::class);
        $this->expectExceptionMessage('not found');

        $loader->load();
    }

    #[Test]
    public function malformedJsonThrowsLoaderError(): void
    {
        $path = $this->writeTempCache('not valid json {{');

        $this->expectException(LoaderError::class);
        $this->expectExceptionMessage('malformed');

        new Cached($path)->load();
    }

    #[Test]
    public function missingAgentsKeyThrowsLoaderError(): void
    {
        $path = $this->writeTempCache(json_encode([
            'generated_at' => '2026-05-17T00:00:00+00:00',
            'source_mtime' => 1747440000,
        ]) ?: '');

        $this->expectException(LoaderError::class);
        $this->expectExceptionMessage("missing required key 'agents'");

        new Cached($path)->load();
    }

    #[Test]
    public function missingGeneratedAtKeyThrowsLoaderError(): void
    {
        $path = $this->writeTempCache(json_encode([
            'agents' => [],
            'source_mtime' => 1747440000,
        ]) ?: '');

        $this->expectException(LoaderError::class);
        $this->expectExceptionMessage("missing required key 'generated_at'");

        new Cached($path)->load();
    }

    #[Test]
    public function missingSourceMtimeKeyThrowsLoaderError(): void
    {
        $path = $this->writeTempCache(json_encode([
            'agents' => [],
            'generated_at' => '2026-05-17T00:00:00+00:00',
        ]) ?: '');

        $this->expectException(LoaderError::class);
        $this->expectExceptionMessage("missing required key 'source_mtime'");

        new Cached($path)->load();
    }

    #[Test]
    public function isStaleReturnsTrueForMissingCacheFile(): void
    {
        $loader = new Cached('/does/not/exist/cache.json');

        self::assertTrue($loader->isStale(self::fixtureAgentDir()));
    }

    #[Test]
    public function isStaleReturnsFalseWhenCacheMtimeIsRecent(): void
    {
        // Write a cache file with source_mtime = far future so it's never stale.
        $path = $this->writeTempCache(json_encode([
            'agents' => [
                \Phalanx\AiProviders\Tests\Fixtures\Agent\Discovered\HoplitesAgent::class,
            ],
            'generated_at' => '2099-01-01T00:00:00+00:00',
            'source_mtime' => PHP_INT_MAX,
        ]) ?: '');

        $loader = new Cached($path);

        self::assertFalse($loader->isStale(self::fixtureAgentDir()));
    }

    #[Test]
    public function isStaleReturnsTrueWhenSourceIsNewer(): void
    {
        // Write a cache with source_mtime = 0 (ancient epoch).
        $path = $this->writeTempCache(json_encode([
            'agents' => [],
            'generated_at' => '1970-01-01T00:00:00+00:00',
            'source_mtime' => 0,
        ]) ?: '');

        $loader = new Cached($path);

        // The discovered/ directory has real PHP files with recent mtimes.
        self::assertTrue($loader->isStale(self::fixtureAgentDir()));
    }

    #[Test]
    public function loadIsIdempotent(): void
    {
        $loader = new Cached(self::fixtureCachePath());

        $r1 = $loader->load();
        $r2 = $loader->load();

        self::assertSame(2, $r1->all()->count());
        self::assertSame(2, $r2->all()->count());
    }

    #[Test]
    public function cacheEntryNotImplementingAgentThrowsLoaderError(): void
    {
        // NonAgentClass exists but does not implement Agent.
        $path = $this->writeTempCache(json_encode([
            'agents' => [
                \Phalanx\AiProviders\Tests\Fixtures\Agent\Discovered\NonAgentClass::class,
            ],
            'generated_at' => '2026-05-17T00:00:00+00:00',
            'source_mtime' => 1747440000,
        ]) ?: '');

        $this->expectException(LoaderError::class);
        $this->expectExceptionMessage('does not implement');

        new Cached($path)->load();
    }

    #[Test]
    public function cacheEntryForNonExistentClassThrowsLoaderError(): void
    {
        $path = $this->writeTempCache(json_encode([
            'agents' => ['App\\Removed\\Agent'],
            'generated_at' => '2026-05-17T00:00:00+00:00',
            'source_mtime' => 1747440000,
        ]) ?: '');

        $this->expectException(LoaderError::class);

        new Cached($path)->load();
    }

    private static function fixtureCachePath(): string
    {
        return dirname(__DIR__, 3) . '/Fixtures/Agent/cache.json';
    }

    private static function fixtureAgentDir(): string
    {
        return dirname(__DIR__, 3) . '/Fixtures/Agent/discovered';
    }

    private function writeTempCache(string $content): string
    {
        return $this->tempWorkspace('ai-providers-cache-')->file('cache.json', $content);
    }
}
