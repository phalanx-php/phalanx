<?php

declare(strict_types=1);

namespace Phalanx\Themis;

final class TomlConfigSource
{
    /** @return array<string, mixed> */
    public static function fromFile(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }

        if (!class_exists(\PhpCollective\Toml\Toml::class)) {
            throw new \RuntimeException(
                'php-collective/toml is required to parse phalanx.toml; install it via composer require php-collective/toml',
            );
        }

        $parsed = \PhpCollective\Toml\Toml::parseFile($path);

        return self::flatten($parsed);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $fullKey = $prefix !== '' ? "{$prefix}.{$key}" : $key;

            if (is_array($value) && !array_is_list($value)) {
                $result = [...$result, ...self::flatten($value, $fullKey)];
            } else {
                $result[$fullKey] = $value;
            }
        }

        return $result;
    }
}
