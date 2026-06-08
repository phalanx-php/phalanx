<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class TempWorkspace
{
    private bool $cleaned = false;

    private function __construct(
        public readonly string $root,
    ) {
    }

    public static function create(string $prefix = 'phalanx-test-'): self
    {
        self::assertValidPrefix($prefix);

        $root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . uniqid($prefix, true);

        if (!mkdir($root, 0o755, true) && !is_dir($root)) {
            throw new RuntimeException("Unable to create temporary workspace: {$root}");
        }

        return new self(realpath($root) ?: $root);
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    public function path(string $relative = ''): string
    {
        $relative = self::normalizeRelativePath($relative);

        return $relative === '' ? $this->root : $this->root . DIRECTORY_SEPARATOR . $relative;
    }

    public function missingPath(string $relative): string
    {
        return $this->path($relative);
    }

    public function dir(string $relative): string
    {
        $path = $this->path($relative);

        if (!is_dir($path) && !mkdir($path, 0o755, true) && !is_dir($path)) {
            throw new RuntimeException("Unable to create temporary directory: {$path}");
        }

        return $path;
    }

    public function file(string $relative, string $contents = ''): string
    {
        $path = $this->path($relative);
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create temporary directory: {$directory}");
        }

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Unable to write temporary file: {$path}");
        }

        return $path;
    }

    public function read(string $relative): string
    {
        $path = $this->path($relative);

        return $this->readOwnedPath($path);
    }

    public function readPath(string $path): string
    {
        return $this->readOwnedPath($this->ownedPath($path));
    }

    public function touch(string $relative, ?int $modifiedAt = null): string
    {
        $path = $this->path($relative);

        if (!touch($path, $modifiedAt ?? time())) {
            throw new RuntimeException("Unable to touch temporary file: {$path}");
        }

        return $path;
    }

    public function cleanup(): void
    {
        if ($this->cleaned) {
            return;
        }

        $this->cleaned = true;

        if (!is_dir($this->root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            $path = $file->getPathname();
            $file->isDir() ? rmdir($path) : unlink($path);
        }

        rmdir($this->root);
    }

    private static function normalizeRelativePath(string $relative): string
    {
        $path = str_replace('\\', '/', $relative);

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1) {
            throw new RuntimeException("Invalid temporary workspace path: {$relative}");
        }

        $relative = trim($path, '/');

        if ($relative === '') {
            return '';
        }

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException("Invalid temporary workspace path: {$relative}");
            }
        }

        return str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private static function assertValidPrefix(string $prefix): void
    {
        if ($prefix === '' || str_contains($prefix, '/') || str_contains($prefix, '\\')) {
            throw new RuntimeException("Invalid temporary workspace prefix: {$prefix}");
        }

        if ($prefix === '.' || $prefix === '..' || preg_match('/^[A-Za-z]:/', $prefix) === 1) {
            throw new RuntimeException("Invalid temporary workspace prefix: {$prefix}");
        }
    }

    private function readOwnedPath(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read temporary file: {$path}");
        }

        return $contents;
    }

    private function ownedPath(string $path): string
    {
        $path = realpath($path) ?: $path;
        $root = rtrim($this->root, DIRECTORY_SEPARATOR);

        if ($path !== $root && !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Path is outside temporary workspace: {$path}");
        }

        return $path;
    }
}
