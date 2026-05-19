<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Process;

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
     * flags, the worker script path, and the autoload argument. The shape
     * matches what StreamingProcess::command(array $argv) expects.
     *
     * Forwards any shared extensions the parent process has loaded that the
     * child will need (openswoole, sqlite3) via `-d extension=<path>`. The
     * extension_dir INI value names the standard .so directory; on macOS
     * with Homebrew PECL installs the extension may live in a separate
     * pecl/ tree, which the Homebrew layout fallback handles.
     *
     * Probes (ini_get, extension_loaded, is_file) run on every call. This
     * runs once per worker start, before the reactor is active, so the
     * filesystem cost is acceptable.
     *
     * @return list<string>
     */
    public function workerCommand(): array
    {
        $extDir  = rtrim((string) ini_get('extension_dir'), '/\\');
        $buildId = basename($extDir);
        $cmd     = [PHP_BINARY];

        foreach (['openswoole', 'sqlite3'] as $extension) {
            if (!extension_loaded($extension)) {
                continue;
            }

            $primary = $extDir . DIRECTORY_SEPARATOR . $extension . '.so';
            if (is_file($primary)) {
                $cmd[] = '-d';
                $cmd[] = "extension={$primary}";
                continue;
            }

            // Homebrew PECL fallback: strip the "no-debug-non-zts-" prefix to
            // get the API date stamp used as the Homebrew PECL directory name.
            $apiDate  = ltrim($buildId, 'no-debug-non-zts-');
            $peclPath = '/opt/homebrew/lib/php/pecl/' . $apiDate . '/' . $extension . '.so';
            if (is_file($peclPath)) {
                $cmd[] = '-d';
                $cmd[] = "extension={$peclPath}";
            }
        }

        $cmd[] = $this->workerScript;
        $cmd[] = "--autoload={$this->autoloadPath}";

        return $cmd;
    }

    private static function findWorkerScript(): string
    {
        $candidates = [
            dirname(__DIR__, 2) . '/bin/phalanx-worker',
            dirname(__DIR__, 4) . '/bin/phalanx-worker',
            dirname(__DIR__, 5) . '/bin/phalanx-worker',
        ];

        return array_find($candidates, static fn(string $path): bool => file_exists($path)) ?? $candidates[0];
    }

    private static function findAutoloadPath(): string
    {
        $candidates = [
            dirname(__DIR__, 3) . '/vendor/autoload.php',
            dirname(__DIR__, 4) . '/vendor/autoload.php',
            dirname(__DIR__, 5) . '/vendor/autoload.php',
            dirname(__DIR__, 7) . '/vendor/autoload.php',
        ];

        return array_find($candidates, static fn(string $path): bool => file_exists($path))
            ?? throw new \RuntimeException('Cannot find autoload.php for worker process');
    }
}
