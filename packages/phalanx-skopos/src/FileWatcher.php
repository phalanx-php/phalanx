<?php

declare(strict_types=1);

namespace Phalanx\Skopos;

use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;

final class FileWatcher
{
    private ?TimerInterface $pollTimer = null;
    /** @var array<string, int> path => mtime */
    private array $snapshot = [];

    /**
     * @param list<string> $paths
     * @param list<string> $extensions
     * @param \Closure(list<string>): void $onChange
     */
    public function __construct(
        private readonly array $paths,
        private readonly array $extensions,
        private readonly \Closure $onChange,
        private readonly float $interval = 1.0,
        private readonly ?string $cwd = null,
    ) {
    }

    public function start(): void
    {
        $this->snapshot = $this->scan();

        $this->pollTimer = Loop::addPeriodicTimer($this->interval, function (): void {
            $current = $this->scan();
            $changed = self::diff($this->snapshot, $current);
            $this->snapshot = $current;

            if ($changed !== []) {
                ($this->onChange)($changed);
            }
        });
    }

    public function stop(): void
    {
        if ($this->pollTimer !== null) {
            Loop::cancelTimer($this->pollTimer);
            $this->pollTimer = null;
        }
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
}
