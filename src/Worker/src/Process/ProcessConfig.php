<?php

declare(strict_types=1);

namespace Phalanx\Worker\Process;

use Phalanx\Support\PackagePaths;
use Phalanx\System\PhpExtensionFlags;

final readonly class ProcessConfig
{
    public function __construct(
        public string $workerScript,
        public string $autoloadPath,
        public float $gracefulTimeout = 5.0,
        public float $forceTimeout = 10.0,
    ) {
    }

    public static function detect(?string $workerScript = null, ?string $autoloadPath = null): self
    {
        return new self(
            workerScript: $workerScript ?? self::findWorkerScript(),
            autoloadPath: $autoloadPath ?? self::findAutoloadPath(),
        );
    }

    /**
     * Build the PHP command array for spawning this worker as a subprocess.
     * Index 0 is PHP_BINARY; subsequent elements are `-d extension=<path>`
     * flags forwarding shared extensions the child needs (swoole, sqlite3),
     * the worker script path, and the autoload argument. The
     * shape matches what StreamingProcess::command(array $argv) expects.
     *
     * @return list<string>
     */
    public function workerCommand(): array
    {
        return [
            PHP_BINARY,
            '-d', 'display_errors=stderr',
            ...PhpExtensionFlags::forLoaded(['swoole', 'sqlite3']),
            $this->workerScript,
            "--autoload={$this->autoloadPath}",
        ];
    }

    private static function findWorkerScript(): string
    {
        $candidates = PackagePaths::ancestorCandidates(__DIR__, 'bin/phalanx-worker');

        return PackagePaths::firstExistingFile($candidates) ?? $candidates[0];
    }

    private static function findAutoloadPath(): string
    {
        $candidates = PackagePaths::ancestorCandidates(__DIR__, 'vendor/autoload.php');

        return PackagePaths::firstExistingFile($candidates)
            ?? throw new \RuntimeException('Cannot find autoload.php for worker process');
    }
}
