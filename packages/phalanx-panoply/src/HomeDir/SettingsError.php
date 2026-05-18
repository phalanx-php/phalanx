<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir;

/**
 * Thrown by HomeDir Settings implementations when a typed accessor fails.
 * All three vendor Settings impls ({@see ClaudeCode\Settings},
 * {@see GeminiCli\Settings}, {@see Codex\Settings}) share this error type
 * so callers can catch uniformly.
 *
 * The `as*()` family of accessors throw this on missing keys or type
 * mismatches. The `get*()` family returns null or a default instead.
 *
 * Final — extension would change exception identity and break callers
 * that catch on this exact type.
 */
final class SettingsError extends \RuntimeException
{
    public static function missingKey(string $key, string $context = ''): self
    {
        $suffix = $context !== '' ? " [{$context}]" : '';

        return new self("Settings key not found: '{$key}'{$suffix}");
    }

    public static function typeMismatch(string $key, string $expected, string $actual): self
    {
        return new self("Settings key '{$key}': expected {$expected}, got {$actual}");
    }
}
