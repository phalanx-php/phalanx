<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Terminal;

use Phalanx\Theatron\Style\ColorMode;

final class Terminal
{
    /**
     * Detect terminal capabilities from the current environment.
     * Call once at boot, pass the resulting TerminalConfig to all consumers.
     *
     * @param array<string, string|false> $env environment variables (COLUMNS, LINES, COLORTERM, TERM, NO_COLOR, CI)
     * @param resource|null $stdout stream to check for TTY (defaults to STDOUT)
     */
    public static function detect(array $env = [], mixed $stdout = null): TerminalConfig
    {
        $stdout ??= \STDOUT;
        $isTty = is_resource($stdout) && stream_isatty($stdout);

        $width = self::resolveSize($env, 'COLUMNS', 80);
        $height = self::resolveSize($env, 'LINES', 24);

        if ($width === 80 || $height === 24) {
            $probed = self::probeSize();

            if ($probed !== null) {
                $width = ($width === 80) ? $probed[1] : $width;
                $height = ($height === 24) ? $probed[0] : $height;
            }
        }

        $colorMode = self::resolveColorMode($env, $isTty);

        return new TerminalConfig($width, $height, $colorMode, $isTty);
    }

    private static function resolveSize(array $env, string $key, int $fallback): int
    {
        $value = $env[$key] ?? null;

        if ($value !== null && is_numeric($value)) {
            return (int) $value;
        }

        return $fallback;
    }

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

    /** @return array{int, int}|null [rows, cols] */
    private static function probeSize(): ?array
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            return self::windowsSize();
        }

        $process = @proc_open(
            'stty size',
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            null,
            ['suppress_errors' => true],
        );

        if (!is_resource($process)) {
            return null;
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($output === false || !preg_match('/(\d+)\s+(\d+)/', $output, $matches)) {
            return null;
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    /** @return array{int, int}|null */
    private static function windowsSize(): ?array
    {
        $process = @proc_open(
            'mode con',
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            null,
            ['suppress_errors' => true],
        );

        if (!is_resource($process)) {
            return null;
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($output === false) {
            return null;
        }

        $lines = null;
        $cols = null;

        if (preg_match('/Lines:\s+(\d+)/i', $output, $m)) {
            $lines = (int) $m[1];
        }

        if (preg_match('/Columns:\s+(\d+)/i', $output, $m)) {
            $cols = (int) $m[1];
        }

        if ($lines === null || $cols === null) {
            return null;
        }

        return [$lines, $cols];
    }
}
