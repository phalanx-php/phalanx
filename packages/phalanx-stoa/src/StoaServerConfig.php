<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Phalanx\Boot\AppContext;

final readonly class StoaServerConfig
{
    public function __construct(
        public string $host = '0.0.0.0',
        public int $port = 8080,
        public float $requestTimeout = 30.0,
        public float $drainTimeout = 30.0,
        public bool $ignitionEnabled = false,
        public bool $quiet = false,
        public ?string $poweredBy = 'Phalanx',
        public ?string $documentRoot = null,
        public bool $enableStaticHandler = false,
        public bool $httpCompression = true,
        public string $logoPath = '/logo.svg',
        public string $faviconPath = '/favicon.ico',
        public string $tagline = 'Expression-based async coordination for PHP 8.4+',
        public string $docsUrl = 'https://github.com/phalanx-php/phalanx',
        public string $githubUrl = 'https://github.com/phalanx-php/phalanx',
        public string $openswooleDocsUrl = 'https://openswoole.com/docs',
    ) {
    }

    public static function defaults(): self
    {
        return new self();
    }

    public static function fromContext(AppContext $context): self
    {
        return self::fromArray($context->values);
    }

    /** @param array<string, mixed> $options */
    public static function fromRuntimeOptions(array $options): self
    {
        return self::fromArray($options);
    }

    /** @param array<string, mixed> $values */
    private static function fromArray(array $values): self
    {
        return new self(
            host: self::stringValue($values, ['host', 'PHALANX_HOST'], '0.0.0.0'),
            port: self::intValue($values, ['port', 'PHALANX_PORT'], 8080),
            requestTimeout: self::floatValue($values, ['request_timeout', 'PHALANX_REQUEST_TIMEOUT'], 30.0),
            drainTimeout: self::floatValue($values, ['drain_timeout', 'PHALANX_DRAIN_TIMEOUT'], 30.0),
            ignitionEnabled: self::boolValue($values, ['ignition_enabled', 'PHALANX_IGNITION_ENABLED'], false),
            quiet: self::boolValue($values, ['quiet', 'PHALANX_QUIET'], false),
            poweredBy: self::nullableStringValue($values, ['powered_by', 'PHALANX_POWERED_BY'], 'Phalanx'),
            documentRoot: self::nullableStringValue($values, ['document_root', 'PHALANX_DOCUMENT_ROOT'], null),
            enableStaticHandler: self::boolValue($values, ['enable_static_handler', 'PHALANX_ENABLE_STATIC_HANDLER'], false),
            httpCompression: self::boolValue($values, ['http_compression', 'PHALANX_HTTP_COMPRESSION'], true),
            logoPath: self::stringValue($values, ['logo_path', 'PHALANX_LOGO_PATH'], '/logo.svg'),
            faviconPath: self::stringValue($values, ['favicon_path', 'PHALANX_FAVICON_PATH'], '/favicon.ico'),
            tagline: self::stringValue($values, ['tagline', 'PHALANX_TAGLINE'], 'Expression-based async coordination for PHP 8.4+'),
            docsUrl: self::stringValue($values, ['docs_url', 'PHALANX_DOCS_URL'], 'https://github.com/phalanx-php/phalanx'),
            githubUrl: self::stringValue($values, ['github_url', 'PHALANX_GITHUB_URL'], 'https://github.com/phalanx-php/phalanx'),
            openswooleDocsUrl: self::stringValue($values, ['openswoole_docs_url', 'PHALANX_OPENSWOOLE_DOCS_URL'], 'https://openswoole.com/docs'),
        );
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $keys
     */
    private static function stringValue(array $values, array $keys, string $default): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $values)) {
                return (string) $values[$key];
            }
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $keys
     */
    private static function intValue(array $values, array $keys, int $default): int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $values)) {
                return (int) $values[$key];
            }
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $keys
     */
    private static function floatValue(array $values, array $keys, float $default): float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $values)) {
                return (float) $values[$key];
            }
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $keys
     */
    private static function boolValue(array $values, array $keys, bool $default): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $values)) {
                $value = $values[$key];

                if (is_bool($value)) {
                    return $value;
                }

                if (is_string($value)) {
                    return match (strtolower($value)) {
                        '1', 'true', 'yes', 'on' => true,
                        '0', 'false', 'no', 'off' => false,
                        default => (bool) $value,
                    };
                }

                return (bool) $value;
            }
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $keys
     */
    private static function nullableStringValue(array $values, array $keys, ?string $default): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $values)) {
                continue;
            }

            $value = $values[$key];

            if ($value === false || $value === null) {
                return null;
            }

            if (is_string($value)) {
                return match (strtolower($value)) {
                    '', '0', 'false', 'no', 'off', 'none' => null,
                    default => $value,
                };
            }

            return (string) $value;
        }

        return $default;
    }
}
