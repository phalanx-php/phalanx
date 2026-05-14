<?php

declare(strict_types=1);

namespace Phalanx\Skopos;

use Closure;
use Phalanx\Scope\Subscription;
use Phalanx\Scope\TaskExecutor;

/**
 * Periodic mtime poller. Schedules a tick on the supplied scope; the
 * Subscription returned by scope.periodic() also auto-cancels on scope
 * disposal, so even callers that never invoke stop() leak nothing.
 *
 * The periodic tick fires on OpenSwoole's Timer thread, which is the
 * reactor thread — not a coroutine context. A synchronous filesystem
 * walk inside the tick stalls every other coroutine for the duration
 * of the walk. Each tick therefore dispatches the scan into a fresh
 * scope.go() coroutine so the work is suspended and yields cleanly.
 */
final class FileWatcher
{
    private ?Subscription $subscription = null;

    private bool $running = false;

    private bool $scanInFlight = false;

    private int $generation = 0;

    /** @var array<string, int> path => mtime */
    private array $snapshot = [];

    /**
     * @param list<string> $paths
     * @param list<string> $extensions
     * @param \Closure(list<string>): void $onChange
     */
    public function __construct(
        private array $paths,
        private array $extensions,
        private Closure $onChange,
        private float $interval = 1.0,
        private ?string $cwd = null,
    ) {
    }

    public function start(TaskExecutor $scope): void
    {
        $this->stop();

        $this->running = true;
        $this->scanInFlight = false;
        $generation = ++$this->generation;
        $this->snapshot = $this->scan();

        $self = $this;
        $this->subscription = $scope->periodic(
            $this->interval,
            static function () use ($self, $scope, $generation): void {
                if (!$self->isActive($generation)) {
                    return;
                }
                /** @dev-cleanup-ignore */
                if ($self->scanInFlight) {
                    return;
                }
                $self->scanInFlight = true;

                $scope->go(static function () use ($self, $generation): void {
                    try {
                        $current = $self->scan();
                        if (!$self->isActive($generation)) {
                            return;
                        }

                        $changed = self::diff($self->snapshot, $current);
                        $self->snapshot = $current;

                        if ($changed !== []) {
                            ($self->onChange)($changed);
                        }
                    } finally {
                        if ($self->generation === $generation) {
                            $self->scanInFlight = false;
                        }
                    }
                }, name: 'skopos.filewatcher.scan');
            },
        );
    }

    public function stop(): void
    {
        $this->running = false;
        ++$this->generation;
        $this->subscription?->cancel();
        $this->subscription = null;
    }

    /**
     * @param array<string, int> $before
     * @param array<string, int> $after
     * @return list<string>
     */
    private static function diff(array $before, array $after): array
    {
        $changed = [];

        foreach ($after as $path => $mtime) {
            if (!isset($before[$path]) || $before[$path] !== $mtime) {
                $changed[] = $path;
            }
        }

        foreach ($before as $path => $_) {
            if (!isset($after[$path])) {
                $changed[] = $path;
            }
        }

        return $changed;
    }

    /** @param list<string> $extensions */
    private static function matchesExtension(string $filename, array $extensions): bool
    {
        foreach ($extensions as $ext) {
            if (str_ends_with($filename, '.' . ltrim($ext, '.'))) {
                return true;
            }
        }

        return false;
    }

    private function isActive(int $generation): bool
    {
        return $this->running && $this->generation === $generation;
    }

    /** @return array<string, int> */
    private function scan(): array
    {
        $result = [];
        $base = $this->cwd ?? getcwd() ?: '.';

        foreach ($this->paths as $path) {
            $absolute = str_starts_with($path, '/') ? $path : $base . '/' . $path;

            if (!is_dir($absolute)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                if (!self::matchesExtension($file->getFilename(), $this->extensions)) {
                    continue;
                }

                $result[$file->getPathname()] = $file->getMTime();
            }
        }

        return $result;
    }
}
