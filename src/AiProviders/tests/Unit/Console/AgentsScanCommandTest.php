<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\Console;

use Phalanx\AiProviders\Console\AgentsScanCommand;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Scope\Scope;
use Phalanx\Testing\UsesTempWorkspace;
use Phalanx\Trace\Trace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgentsScanCommandTest extends TestCase
{
    use UsesTempWorkspace;

    private string $cacheFile;

    #[Test]
    public function commandWritesCacheFile(): void
    {
        $command = new AgentsScanCommand(
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
        $command = new AgentsScanCommand(
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
        $command = new AgentsScanCommand(
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
        $command = new AgentsScanCommand(
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
        $command = new AgentsScanCommand(
            self::discoveredDir(),
            self::discoveredPrefix(),
            $this->cacheFile,
        );

        self::assertSame(0, $command(self::makeScope()));
    }

    #[Test]
    public function secondRunWithUnchangedSourceIsIdempotent(): void
    {
        $command = new AgentsScanCommand(
            self::discoveredDir(),
            self::discoveredPrefix(),
            $this->cacheFile,
        );

        $command(self::makeScope());
        $contentAfterFirstRun = file_get_contents($this->cacheFile);

        $command(self::makeScope());
        $contentAfterSecondRun = file_get_contents($this->cacheFile);

        self::assertSame($contentAfterFirstRun, $contentAfterSecondRun);
    }

    #[Test]
    public function commandCreatesOutputDirectoryIfNeeded(): void
    {
        $nestedPath = $this->tempWorkspace('ai-providers-scan-')->path('nested/cache.json');

        $command = new AgentsScanCommand(
            self::discoveredDir(),
            self::discoveredPrefix(),
            $nestedPath,
        );

        $result = $command(self::makeScope());

        self::assertSame(0, $result);
        self::assertFileExists($nestedPath);
    }

    #[Test]
    public function emptySourceDirectoryWritesCacheWithEmptyAgents(): void
    {
        $emptyDir = $this->tempWorkspace('ai-providers-empty-')->dir('source');

        $command = new AgentsScanCommand(
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
    }

    #[Test]
    public function sourceMtimeMatchesMaxMtimeOfFixtureFiles(): void
    {
        $knownEpoch = 1700000000;
        $tempDir = $this->tempWorkspace('ai-providers-mtime-')->dir('source');
        $tempFile = $this->tempWorkspace()->file('source/Sparta.php', "<?php\n// mtime fixture\n");
        touch($tempFile, $knownEpoch);

        $command = new AgentsScanCommand(
            $tempDir,
            'App\\Agents',
            $this->cacheFile,
        );
        $command(self::makeScope());

        $payload = self::readCache($this->cacheFile);

        self::assertSame($knownEpoch, $payload['source_mtime']);
    }

    #[Test]
    public function writeCacheThrowsRuntimeExceptionWhenPathIsInvalid(): void
    {
        $invalidPath = $this->tempWorkspace('ai-providers-invalid-')->file('not-a-directory', '') . '/cache.json';

        $command = new AgentsScanCommand(
            self::discoveredDir(),
            self::discoveredPrefix(),
            $invalidPath,
        );

        $this->expectException(\RuntimeException::class);

        $command(self::makeScope());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheFile = $this->tempWorkspace('ai-providers-scan-')->path('cache.json');
    }

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
