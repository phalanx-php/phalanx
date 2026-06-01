<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Drawing;

use Phalanx\Theatron\Tui\Styles\ColorMode;

final class Terminal
{
    /**
     * @param array<string, string|false> $env
     * @param resource|null $stdout
     */
    public static function detect(array $env = [], mixed $stdout = null): TerminalConfig
    {
        $stdout ??= \STDOUT;
        $isTty = is_resource($stdout) && stream_isatty($stdout);

        $width = self::resolveSize($env, 'COLUMNS', 80);
        $height = self::resolveSize($env, 'LINES', 24);

        $colorMode = self::resolveColorMode($env, $isTty);

        return new TerminalConfig($width, $height, $colorMode, $isTty);
    }

    /** @return array{int, int} [width, height], falling back to 80x24 */
    public static function size(): array
    {
        return [
            self::resolveEnvironmentSize('COLUMNS', 80),
            self::resolveEnvironmentSize('LINES', 24),
        ];
    }

    /** @param array<string, string|false> $env */
    private static function resolveSize(array $env, string $key, int $fallback): int
    {
        $value = $env[$key] ?? null;

        if ($value !== null && is_numeric($value)) {
            return (int) $value;
        }

        return $fallback;
    }

    private static function resolveEnvironmentSize(string $key, int $fallback): int
    {
        $value = getenv($key);

        if ($value !== false && is_numeric($value)) {
            return (int) $value;
        }

        return $fallback;
    }

    /** @param array<string, string|false> $env */
    private static function resolveColorMode(array $env, bool $isTty): ColorMode
    {
        if (isset($env['NO_COLOR'])) {
            return ColorMode::Ansi4;
        }

        if (!$isTty) {
            return ColorMode::Ansi4;
        }

        $colorTerm = $env['COLORTERM'] ?? null;

        if ($colorTerm === 'truecolor' || $colorTerm === '24bit') {
            return ColorMode::Ansi24;
        }

        $term = $env['TERM'] ?? '';

        if (is_string($term) && str_contains($term, '256color')) {
            return ColorMode::Ansi8;
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            return ColorMode::Ansi24;
        }

        if (isset($env['CI'])) {
            return ColorMode::Ansi8;
        }

        return ColorMode::Ansi4;
    }
}
