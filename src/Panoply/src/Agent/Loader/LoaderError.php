<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Agent\Loader;

/**
 * Thrown by Agent loaders when discovery or instantiation fails: a class
 * carries {@see \Phalanx\Panoply\Agent\Discovered} but does not implement
 * {@see \Phalanx\Panoply\Agent}, has a non-trivial constructor, a manifest
 * entry is not instantiable, or a cache file is missing or malformed.
 *
 * Final — extension would change exception identity and break catch blocks.
 */
final class LoaderError extends \RuntimeException
{
    public static function notAnAgent(string $class): self
    {
        return new self(
            "Class '{$class}' carries #[Discovered] but does not implement Phalanx\\Panoply\\Agent.",
        );
    }

    public static function nonTrivialConstructor(string $class): self
    {
        return new self(
            "Class '{$class}' carries #[Discovered] but has a non-trivial constructor. " .
            "Discovered agents must be instantiable with no constructor arguments.",
        );
    }

    public static function notInstantiable(string $class, string $reason = ''): self
    {
        $suffix = $reason !== '' ? ": {$reason}" : '.';

        return new self("Agent class '{$class}' is not instantiable{$suffix}");
    }

    public static function cacheNotFound(string $path): self
    {
        return new self("Agent cache file not found: '{$path}'.");
    }

    public static function cacheMalformed(string $path, string $reason = ''): self
    {
        $suffix = $reason !== '' ? ": {$reason}" : '.';

        return new self("Agent cache file '{$path}' is malformed{$suffix}");
    }

    public static function manifestNotFound(string $path): self
    {
        return new self("Agent manifest file not found: '{$path}'.");
    }

    public static function manifestInvalid(string $path, string $reason): self
    {
        return new self("Agent manifest '{$path}' failed validation: {$reason}");
    }
}
