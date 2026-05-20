<?php

declare(strict_types=1);

namespace Phalanx\System;

/**
 * Builds `-d extension=<path>` CLI flags for each currently-loaded PHP
 * extension that a child PHP process must also load. Required when an
 * exec'd child boots a Phalanx kernel that depends on shared extensions
 * (openswoole, sqlite3) — process replacement does NOT inherit the parent's
 * `-d extension=...` flags.
 *
 * Path resolution checks the standard `extension_dir` INI value first,
 * then falls back to the Homebrew PECL layout (`/opt/homebrew/lib/php/pecl/
 * <api-date>/`) common on macOS when PHP and PECL extensions live in
 * separate trees.
 */
final class PhpExtensionFlags
{
    private const string HOMEBREW_BUILD_PREFIX = 'no-debug-non-zts-';

    private const string HOMEBREW_PECL_ROOT    = '/opt/homebrew/lib/php/pecl';

    private function __construct()
    {
    }

    /**
     * Return `-d extension=<path>` argv pairs for every name in $extensions
     * that the parent process has loaded and whose .so file is resolvable.
     * Missing or unloadable extensions are silently skipped — the child will
     * still boot if it does not actually need that extension.
     *
     * @param  list<string> $extensions
     * @return list<string>
     */
    public static function forLoaded(array $extensions): array
    {
        $extDir   = rtrim((string) ini_get('extension_dir'), '/\\');
        $buildId  = basename($extDir);
        $iniFiles = null;
        $flags    = [];

        foreach ($extensions as $extension) {
            if (!extension_loaded($extension)) {
                continue;
            }

            $iniFiles ??= self::collectIniFiles();
            if (self::isIniConfigured($extension, $iniFiles)) {
                continue;
            }

            $path = self::resolvePath($extension, $extDir, $buildId);
            if ($path === null) {
                continue;
            }

            $flags[] = '-d';
            $flags[] = "extension={$path}";
        }

        return $flags;
    }

    /** @return list<string> */
    private static function collectIniFiles(): array
    {
        $files = [];

        $loaded = php_ini_loaded_file();
        if ($loaded !== false) {
            $files[] = $loaded;
        }

        $scanned = php_ini_scanned_files();
        if ($scanned !== false && $scanned !== '') {
            foreach (explode(',', $scanned) as $file) {
                $file = trim($file);
                if ($file !== '') {
                    $files[] = $file;
                }
            }
        }

        return $files;
    }

    /** @param list<string> $iniFiles */
    private static function isIniConfigured(string $extension, array $iniFiles): bool
    {
        $pattern = '/^\s*extension\s*=\s*[^;]*?' . preg_quote($extension, '/') . '/mi';

        foreach ($iniFiles as $file) {
            if (!is_file($file)) {
                continue;
            }

            $content = file_get_contents($file);
            if ($content !== false && preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    private static function resolvePath(string $extension, string $extDir, string $buildId): ?string
    {
        $primary = $extDir . DIRECTORY_SEPARATOR . $extension . '.so';
        if (is_file($primary)) {
            return $primary;
        }

        $apiDate = str_starts_with($buildId, self::HOMEBREW_BUILD_PREFIX)
            ? substr($buildId, strlen(self::HOMEBREW_BUILD_PREFIX))
            : $buildId;

        $peclPath = self::HOMEBREW_PECL_ROOT . '/' . $apiDate . '/' . $extension . '.so';

        return is_file($peclPath) ? $peclPath : null;
    }
}
