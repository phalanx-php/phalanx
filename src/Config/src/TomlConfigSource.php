<?php

declare(strict_types=1);

namespace Phalanx\Config;

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

        $parsed = \PhpCollective\Toml\Toml::decodeFile($path);

        return self::toContext($parsed);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function toContext(array $data): array
    {
        $context = self::flatten($data);

        if (isset($data['app']) && is_array($data['app']) && array_key_exists('name', $data['app'])) {
            $context['APP_NAME'] = $data['app']['name'];
        }

        if (isset($data['env']) && is_array($data['env'])) {
            foreach ($data['env'] as $key => $value) {
                if (is_string($key)) {
                    $context[$key] = $value;
                }
            }
        }

        return $context;
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
                $result[$fullKey] = $value;
                $result = [...$result, ...self::flatten($value, $fullKey)];
            } else {
                $result[$fullKey] = $value;
            }
        }

        return $result;
    }
}
