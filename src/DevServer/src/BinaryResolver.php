<?php

declare(strict_types=1);

namespace Phalanx\DevServer;

use RuntimeException;

final class BinaryResolver
{
    /** @param array<string, string> $env */
    public static function resolve(Binary $binary, array $env = []): string
    {
        $path = self::find($binary->value, $binary->fallbacks($env), $env);

        if ($path !== null) {
            return $path;
        }

        throw new RuntimeException(
            sprintf("Binary '%s' not found. %s", $binary->value, $binary->installHint()),
        );
    }

    /**
     * @param list<string> $fallbackPaths
     * @param array<string, string> $env
     */
    private static function find(string $name, array $fallbackPaths, array $env): ?string
    {
        $pathStr = $env['PATH'] ?? $env['Path'] ?? '';
        $dirs = $pathStr !== '' ? explode(\PATH_SEPARATOR, $pathStr) : [];

        $suffixes = [];
        if (\DIRECTORY_SEPARATOR === '\\') {
            $pathExt = $env['PATHEXT'] ?? '';
            $suffixes = $pathExt !== '' ? explode(\PATH_SEPARATOR, $pathExt) : ['.exe', '.bat', '.cmd', '.com'];
        }

        $suffixes = pathinfo($name, \PATHINFO_EXTENSION) !== ''
            ? ['', ...$suffixes]
            : [...$suffixes, ''];

        foreach ($suffixes as $suffix) {
            foreach ($dirs as $dir) {
                if ($dir === '') {
                    $dir = '.';
                }

                $file = $dir . \DIRECTORY_SEPARATOR . $name . $suffix;
                if (@is_file($file) && (\DIRECTORY_SEPARATOR === '\\' || @is_executable($file))) {
                    return $file;
                }
            }
        }

        foreach ($fallbackPaths as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}
