<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Phalanx\Boot\AppContext;

final readonly class ServerConfig
{
    private const string DEFAULT_DOCS_URL = 'https://github.com/phalanx-php/phalanx';
    private const string DEFAULT_GITHUB_URL = 'https://github.com/phalanx-php/phalanx';
    private const string DEFAULT_SWOOLE_DOCS_URL = 'https://wiki.swoole.com';
    private const string DEFAULT_PHP_DOCS_URL = 'https://php.net/docs';
    private const string DEFAULT_PHP_LOGO_URL = 'https://www.php.net/images/logos/php-logo-white.svg';
    private const string DEFAULT_SWOOLE_LOGO_URL = 'https://www.swoole.com/static/img/logo-white.png';
    private const string DEFAULT_PHALANX_MARK_URL = 'https://raw.githubusercontent.com/phalanx-php/phalanx/refs/heads/main/mark.png';
    private const string DEFAULT_LUCIDE_SCRIPT_URL = 'https://unpkg.com/lucide@latest';
    private const string DEFAULT_FONT_STYLESHEET_URL = 'https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700&family=Inter:wght@400;600;800&display=swap';
    private const string DEFAULT_FONT_PRECONNECT_URL = 'https://fonts.googleapis.com';
    private const string DEFAULT_FONT_STATIC_PRECONNECT_URL = 'https://fonts.gstatic.com';
    private const string DEFAULT_PRISM_THEME_STYLESHEET_URL = 'https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css';
    private const string DEFAULT_PRISM_LINE_NUMBERS_STYLESHEET_URL = 'https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.css';
    private const string DEFAULT_PRISM_LINE_HIGHLIGHT_STYLESHEET_URL = 'https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-highlight/prism-line-highlight.min.css';
    private const string DEFAULT_PRISM_SCRIPT_URL = 'https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js';
    private const string DEFAULT_PRISM_PHP_SCRIPT_URL = 'https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-php.min.js';
    private const string DEFAULT_PRISM_LINE_NUMBERS_SCRIPT_URL = 'https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.js';
    private const string DEFAULT_PRISM_LINE_HIGHLIGHT_SCRIPT_URL = 'https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-highlight/prism-line-highlight.min.js';

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
        public string $tagline = 'Supervised execution framework for modern PHP',
        public string $docsUrl = self::DEFAULT_DOCS_URL,
        public string $githubUrl = self::DEFAULT_GITHUB_URL,
        public string $swooleDocsUrl = self::DEFAULT_SWOOLE_DOCS_URL,
        public string $phpDocsUrl = self::DEFAULT_PHP_DOCS_URL,
        public string $phpLogoUrl = self::DEFAULT_PHP_LOGO_URL,
        public string $swooleLogoUrl = self::DEFAULT_SWOOLE_LOGO_URL,
        public string $phalanxMarkUrl = self::DEFAULT_PHALANX_MARK_URL,
        public string $lucideScriptUrl = self::DEFAULT_LUCIDE_SCRIPT_URL,
        public string $fontStylesheetUrl = self::DEFAULT_FONT_STYLESHEET_URL,
        public string $fontPreconnectUrl = self::DEFAULT_FONT_PRECONNECT_URL,
        public string $fontStaticPreconnectUrl = self::DEFAULT_FONT_STATIC_PRECONNECT_URL,
        public string $prismThemeStylesheetUrl = self::DEFAULT_PRISM_THEME_STYLESHEET_URL,
        public string $prismLineNumbersStylesheetUrl = self::DEFAULT_PRISM_LINE_NUMBERS_STYLESHEET_URL,
        public string $prismLineHighlightStylesheetUrl = self::DEFAULT_PRISM_LINE_HIGHLIGHT_STYLESHEET_URL,
        public string $prismScriptUrl = self::DEFAULT_PRISM_SCRIPT_URL,
        public string $prismPhpScriptUrl = self::DEFAULT_PRISM_PHP_SCRIPT_URL,
        public string $prismLineNumbersScriptUrl = self::DEFAULT_PRISM_LINE_NUMBERS_SCRIPT_URL,
        public string $prismLineHighlightScriptUrl = self::DEFAULT_PRISM_LINE_HIGHLIGHT_SCRIPT_URL,
        public ?string $banner = null,
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
            docsUrl: self::stringValue($values, ['docs_url', 'PHALANX_DOCS_URL'], self::DEFAULT_DOCS_URL),
            githubUrl: self::stringValue($values, ['github_url', 'PHALANX_GITHUB_URL'], self::DEFAULT_GITHUB_URL),
            swooleDocsUrl: self::stringValue($values, ['swoole_docs_url', 'PHALANX_SWOOLE_DOCS_URL'], self::DEFAULT_SWOOLE_DOCS_URL),
            phpDocsUrl: self::stringValue($values, ['php_docs_url', 'PHALANX_PHP_DOCS_URL'], self::DEFAULT_PHP_DOCS_URL),
            phpLogoUrl: self::stringValue($values, ['php_logo_url', 'PHALANX_PHP_LOGO_URL'], self::DEFAULT_PHP_LOGO_URL),
            swooleLogoUrl: self::stringValue($values, ['swoole_logo_url', 'PHALANX_SWOOLE_LOGO_URL'], self::DEFAULT_SWOOLE_LOGO_URL),
            phalanxMarkUrl: self::stringValue($values, ['phalanx_mark_url', 'PHALANX_MARK_URL'], self::DEFAULT_PHALANX_MARK_URL),
            lucideScriptUrl: self::stringValue($values, ['lucide_script_url', 'PHALANX_LUCIDE_SCRIPT_URL'], self::DEFAULT_LUCIDE_SCRIPT_URL),
            fontStylesheetUrl: self::stringValue($values, ['font_stylesheet_url', 'PHALANX_FONT_STYLESHEET_URL'], self::DEFAULT_FONT_STYLESHEET_URL),
            fontPreconnectUrl: self::stringValue($values, ['font_preconnect_url', 'PHALANX_FONT_PRECONNECT_URL'], self::DEFAULT_FONT_PRECONNECT_URL),
            fontStaticPreconnectUrl: self::stringValue($values, ['font_static_preconnect_url', 'PHALANX_FONT_STATIC_PRECONNECT_URL'], self::DEFAULT_FONT_STATIC_PRECONNECT_URL),
            prismThemeStylesheetUrl: self::stringValue($values, ['prism_theme_stylesheet_url', 'PHALANX_PRISM_THEME_STYLESHEET_URL'], self::DEFAULT_PRISM_THEME_STYLESHEET_URL),
            prismLineNumbersStylesheetUrl: self::stringValue($values, ['prism_line_numbers_stylesheet_url', 'PHALANX_PRISM_LINE_NUMBERS_STYLESHEET_URL'], self::DEFAULT_PRISM_LINE_NUMBERS_STYLESHEET_URL),
            prismLineHighlightStylesheetUrl: self::stringValue($values, ['prism_line_highlight_stylesheet_url', 'PHALANX_PRISM_LINE_HIGHLIGHT_STYLESHEET_URL'], self::DEFAULT_PRISM_LINE_HIGHLIGHT_STYLESHEET_URL),
            prismScriptUrl: self::stringValue($values, ['prism_script_url', 'PHALANX_PRISM_SCRIPT_URL'], self::DEFAULT_PRISM_SCRIPT_URL),
            prismPhpScriptUrl: self::stringValue($values, ['prism_php_script_url', 'PHALANX_PRISM_PHP_SCRIPT_URL'], self::DEFAULT_PRISM_PHP_SCRIPT_URL),
            prismLineNumbersScriptUrl: self::stringValue($values, ['prism_line_numbers_script_url', 'PHALANX_PRISM_LINE_NUMBERS_SCRIPT_URL'], self::DEFAULT_PRISM_LINE_NUMBERS_SCRIPT_URL),
            prismLineHighlightScriptUrl: self::stringValue($values, ['prism_line_highlight_script_url', 'PHALANX_PRISM_LINE_HIGHLIGHT_SCRIPT_URL'], self::DEFAULT_PRISM_LINE_HIGHLIGHT_SCRIPT_URL),
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
