<?php

declare(strict_types=1);

namespace Convoy\Parallel\Process;

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

    private static function findWorkerScript(): string
    {
        $candidates = [
            dirname(__DIR__, 2) . '/bin/convoy-worker',
            dirname(__DIR__, 4) . '/bin/convoy-worker',
            dirname(__DIR__, 5) . '/bin/convoy-worker',
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return $candidates[0];
    }

    private static function findAutoloadPath(): string
    {
        $candidates = [
            dirname(__DIR__, 3) . '/vendor/autoload.php',
            dirname(__DIR__, 4) . '/vendor/autoload.php',
            dirname(__DIR__, 5) . '/vendor/autoload.php',
            dirname(__DIR__, 7) . '/vendor/autoload.php',
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        throw new \RuntimeException('Cannot find autoload.php for worker process');
    }
}
