<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Agent\Loader\Support;

/**
 * Shared filesystem helper for computing the maximum PHP file mtime across a
 * directory tree. Used by {@see \Phalanx\Panoply\Agent\Loader\Cached} and
 * {@see \Phalanx\Panoply\Archon\PanoplyAgentsScanCommand} to check whether a
 * cache is stale relative to its source directory.
 *
 * Not instantiable — static utility only.
 */
final class Mtime
{
    private function __construct()
    {
    }

    /**
     * Walk `$directory` recursively and return the maximum `filemtime()` across
     * all `*.php` files found. Returns 0 when the directory is missing, empty,
     * or contains no PHP files.
     */
    public static function maxIn(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $max      = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS,
            ),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $mtime = $file->getMTime();

            if ($mtime > $max) {
                $max = $mtime;
            }
        }

        return $max;
    }
}
