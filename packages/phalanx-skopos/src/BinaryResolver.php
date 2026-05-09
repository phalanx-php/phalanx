<?php

declare(strict_types=1);

namespace Phalanx\Skopos;

use RuntimeException;

final class BinaryResolver
{
    /** @param array<string, string> $env */
    public static function bun(array $env = []): string
    {
        $home = $env['HOME'] ?? $env['USERPROFILE'] ?? '';
        $fallbacks = $home !== '' ? [$home . '/.bun/bin/bun'] : [];

        return self::resolve('bun', $fallbacks, 'Install bun: curl -fsSL https://bun.sh/install | bash');
    }

    public static function tailwindcss(): string
    {
        return self::resolve('tailwindcss', [
            './node_modules/.bin/tailwindcss',
        ], 'Install Tailwind CSS standalone: https://tailwindcss.com/blog/standalone-cli');
    }

    public static function sass(): string
    {
        return self::resolve('sass', [
            './node_modules/.bin/sass',
        ], 'Install Dart Sass: https://sass-lang.com/install/');
    }

    public static function node(): string
    {
        return self::resolve('node', [], 'Install Node.js: https://nodejs.org/');
    }

    public static function php(): string
    {
        return self::resolve('php', [
            PHP_BINARY,
        ], 'PHP binary not found in PATH');
    }

    /** @param list<string> $fallbackPaths */
    private static function resolve(string $binary, array $fallbackPaths, string $installHint): string
    {
        $which = self::which($binary);

        if ($which !== null) {
            return $which;
        }

        foreach ($fallbackPaths as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        throw new RuntimeException(
            "Binary '{$binary}' not found. {$installHint}"
        );
    }

    private static function which(string $binary): ?string
    {
        // Defensive: although call sites today supply hardcoded binary names,
        // the helper must escape its argument so future call sites can pass
        // user-derived input safely.
        $result = shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null');

        if ($result === null || $result === '' || $result === false) {
            return null;
        }

        $path = trim($result);

        return $path !== '' ? $path : null;
    }
}
