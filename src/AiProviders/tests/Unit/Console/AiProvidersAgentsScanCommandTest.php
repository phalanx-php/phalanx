<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\Console;

use Phalanx\AiProviders\Console\AiProvidersAgentsScanCommand;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Scope\Scope;
use Phalanx\Trace\Trace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see AiProvidersAgentsScanCommand}.
 *
 * Uses a minimal {@see Scope} stub — the command performs no scope
 * interactions; the stub just satisfies the type constraint so we can invoke
 * the command directly without booting an Runtime application.
 *
 * Tests operate against the fixture discovered/ directory to exercise the
 * full scan → cache-write pipeline.
 */
final class AiProvidersAgentsScanCommandTest extends TestCase
{
    private string $cacheFile;

    // ── test methods ───────────────────────────────────────────────────────────

    #[Test]
    public function commandWritesCacheFile(): void
    {
        $command = new AiProvidersAgentsScanCommand(
            self::discoveredDir(),
            self::discoveredPrefix(),
            $this->cacheFile,
        );

        $result = $command(self::makeScope());

        self::assertSame(0, $result);
        self::assertFileExists($this->cacheFile);
    }

    #[Test]
    public function cacheJsonContainsExpectedAgentFqcns(): void
    {
        $command = new AiProvidersAgentsScanCommand(
            self::discoveredDir(),
            self::discoveredPrefix(),
            $this->cacheFile,
        );

        $command(self::makeScope());

        $payload = self::readCache($this->cacheFile);

        self::assertArrayHasKey('agents', $payload);
        self::assertContains(
            \Phalanx\AiProviders\Tests\Fixtures\Agent\Discovered\HoplitesAgent::class,
            $payload['agents'],
        );
        self::assertContains(
            \Phalanx\AiProviders\Tests\Fixtures\Agent\Discovered\PhalanxAgent::class,
            $payload['agents'],
        );
    }

    #[Test]
    public function cacheJsonContainsSourceMtime(): void
    {
        $command = new AiProvidersAgentsScanCommand(
            self::discoveredDir(),
            self::discoveredPrefix(),
            $this->cacheFile,
        );

        $command(self::makeScope());

        $payload = self::readCache($this->cacheFile);

        self::assertArrayHasKey('source_mtime', $payload);
        self::assertIsInt($payload['source_mtime']);
        self::assertGreaterThan(0, $payload['source_mtime']);
    }

    #[Test]
    public function cacheJsonContainsGeneratedAt(): void
    {
        $command = new AiProvidersAgentsScanCommand(
            self::discoveredDir(),
            self::discoveredPrefix(),
            $this->cacheFile,
        );

        $command(self::makeScope());

        $payload = self::readCache($this->cacheFile);

        self::assertArrayHasKey('generated_at', $payload);
        self::assertIsString($payload['generated_at']);
        self::assertNotEmpty($payload['generated_at']);
    }

    #[Test]
    public function commandReturnsZero(): void
    {
        $command = new AiProvidersAgentsScanCommand(
            self::discoveredDir(),
            self::discoveredPrefix(),
            $this->cacheFile,
        );

        self::assertSame(0, $command(self::makeScope()));
    }

    #[Test]
    public function secondRunWithUnchangedSourceIsIdempotent(): void
    {
        $command = new AiProvidersAgentsScanCommand(
            self::discoveredDir(),
            self::discoveredPrefix(),
            $this->cacheFile,
        );

        $command(self::makeScope());
        $contentAfterFirstRun = file_get_contents($this->cacheFile);

        // Second run — source is unchanged, cache should be preserved.
        $command(self::makeScope());
        $contentAfterSecondRun = file_get_contents($this->cacheFile);

        self::assertSame($contentAfterFirstRun, $contentAfterSecondRun);
    }

    #[Test]
    public function commandCreatesOutputDirectoryIfNeeded(): void
    {
        $nestedPath = sys_get_temp_dir() . '/ai-providers_scan_nested_' . uniqid() . '/cache.json';

        try {
            $command = new AiProvidersAgentsScanCommand(
                self::discoveredDir(),
                self::discoveredPrefix(),
                $nestedPath,
            );

            $result = $command(self::makeScope());

            self::assertSame(0, $result);
            self::assertFileExists($nestedPath);
        } finally {
            if (is_file($nestedPath)) {
                unlink($nestedPath);
                rmdir(dirname($nestedPath));
            }
        }
    }

    #[Test]
    public function emptySourceDirectoryWritesCacheWithEmptyAgents(): void
    {
        $emptyDir = sys_get_temp_dir() . '/ai-providers_empty_' . uniqid();
        mkdir($emptyDir, 0755);

        try {
            $command = new AiProvidersAgentsScanCommand(
                $emptyDir,
                'App\\Agents',
                $this->cacheFile,
            );

            $result = $command(self::makeScope());
            $payload = self::readCache($this->cacheFile);

            self::assertSame(0, $result);
            self::assertArrayHasKey('agents', $payload);
            self::assertIsArray($payload['agents']);
            self::assertSame([], $payload['agents']);
        } finally {
            rmdir($emptyDir);
        }
    }

    #[Test]
    public function sourceMtimeMatchesMaxMtimeOfFixtureFiles(): void
    {
        // Touch a known fixture file to a fixed epoch so max-mtime is predictable.
        $fixtureFile = self::discoveredDir() . '/HoplitesAgent.php';
        $originalTime = filemtime($fixtureFile);
        $knownEpoch = 1700000000; // 2023-11-14T22:13:20Z — a fixed past timestamp

        // Set the mtime to a known value; touch the other files to 0 so they
        // don't accidentally have a newer mtime than $knownEpoch.
        // We only need to verify the max-mtime logic, so touching HoplitesAgent
        // to a known epoch and checking payload['source_mtime'] === that epoch
        // only works reliably if we set ALL fixtures to <= $knownEpoch.
        // Instead, use a temp directory with a single PHP file at $knownEpoch.
        $tempDir = sys_get_temp_dir() . '/ai-providers_mtime_' . uniqid();
        mkdir($tempDir, 0755);
        $tempFile = $tempDir . '/Sparta.php';
        file_put_contents($tempFile, "<?php\n// mtime fixture\n");
        touch($tempFile, $knownEpoch);

        try {
            $command = new AiProvidersAgentsScanCommand(
                $tempDir,
                'App\\Agents',
                $this->cacheFile,
            );
            $command(self::makeScope());

            $payload = self::readCache($this->cacheFile);

            self::assertSame($knownEpoch, $payload['source_mtime']);
        } finally {
            unlink($tempFile);
            rmdir($tempDir);
            // Restore original mtime on the fixture (best-effort).
            if ($originalTime !== false) {
                touch($fixtureFile, $originalTime);
            }
        }
    }

    #[Test]
    public function writeCacheThrowsRuntimeExceptionWhenPathIsInvalid(): void
    {
        // /dev/null/sub is an always-invalid target: /dev/null is a character
        // device, so mkdir(/dev/null/sub) and file_put_contents will both fail.
        $invalidPath = '/dev/null/ai-providers_test_' . uniqid() . '/cache.json';

        $command = new AiProvidersAgentsScanCommand(
            self::discoveredDir(),
            self::discoveredPrefix(),
            $invalidPath,
        );

        $this->expectException(\RuntimeException::class);

        $command(self::makeScope());
    }

    // ── lifecycle ──────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheFile = tempnam(sys_get_temp_dir(), 'ai-providers_scan_test_') . '.json';
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (is_file($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private static function discoveredDir(): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/Agent/discovered';
    }

    private static function discoveredPrefix(): string
    {
        return 'Phalanx\\AiProviders\\Tests\\Fixtures\\Agent\\Discovered';
    }

    /**
     * @return array<string, mixed>
     */
    private static function readCache(string $path): array
    {
        $raw = file_get_contents($path);
        self::assertNotFalse($raw);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * Minimal {@see Scope} stub. The command performs zero scope interactions —
     * the stub satisfies the type constraint so we can invoke the command
     * directly without booting an Runtime application.
     */
    private static function makeScope(): Scope
    {
        return new class () implements Scope {
            public RuntimeContext $runtime {
                get => throw new \RuntimeException('not implemented in stub');
            }

            public function service(string $type): object
            {
                throw new \RuntimeException('not implemented in stub');
            }

            public function trace(): Trace
            {
                throw new \RuntimeException('not implemented in stub');
            }
        };
    }
}
