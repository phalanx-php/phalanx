<?php

declare(strict_types=1);

namespace Phalanx\Athena\Examples\Support;

use Phalanx\Boot\AppContext;

/**
 * TCP probe + model query for a locally running Ollama instance.
 *
 * Reads OLLAMA_BASE_URL from the supplied context (defaulting to the
 * standard local address). If a TCP connection succeeds, merges
 * OLLAMA_ENABLED=true and, when OLLAMA_MODEL is absent, auto-selects
 * the first non-embedding model from /api/tags.
 *
 * Returns a new AppContext with the merged keys so callers stay
 * context-first throughout.
 */
final class OllamaAutoDetect
{
    private const string DEFAULT_BASE_URL = 'http://localhost:11434';
    private const float  CONNECT_TIMEOUT  = 0.15;

    public function __invoke(AppContext $context): AppContext
    {
        $baseUrl = $context->get(DemoContextKeys::OLLAMA_BASE_URL, self::DEFAULT_BASE_URL);
        if (!is_string($baseUrl) || $baseUrl === '') {
            $baseUrl = self::DEFAULT_BASE_URL;
        }

        if (!self::canConnect($baseUrl)) {
            return $context;
        }

        if (!$context->has(DemoContextKeys::OLLAMA_ENABLED)) {
            $context = $context->with(DemoContextKeys::OLLAMA_ENABLED, true);
        }

        if (!$context->has(DemoContextKeys::OLLAMA_MODEL)) {
            $model = self::detectModel($baseUrl);
            if ($model !== null) {
                $context = $context->with(DemoContextKeys::OLLAMA_MODEL, $model);
            }
        }

        return $context;
    }

    private static function canConnect(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        if (!is_string($host) || $host === '') {
            return false;
        }

        $socket = @fsockopen(
            $host,
            is_int($port) ? $port : 80,
            $errorCode,
            $errorMessage,
            self::CONNECT_TIMEOUT,
        );

        if ($socket === false) {
            return false;
        }

        fclose($socket);
        return true;
    }

    private static function detectModel(string $baseUrl): ?string
    {
        $json = self::httpGet(rtrim($baseUrl, '/') . '/api/tags');
        if ($json === null) {
            return null;
        }

        $payload = json_decode($json, true);
        if (!is_array($payload) || !isset($payload['models']) || !is_array($payload['models'])) {
            return null;
        }

        foreach ($payload['models'] as $model) {
            if (!is_array($model)) {
                continue;
            }

            $name    = $model['name'] ?? null;
            $details = $model['details'] ?? [];
            $family  = is_array($details) ? (string) ($details['family'] ?? '') : '';

            // Skip embedding models; accept the first generative model found.
            if (is_string($name) && $name !== '' && !str_contains($family, 'bert')) {
                return $name;
            }
        }

        return null;
    }

    private static function httpGet(string $url): ?string
    {
        set_error_handler(static fn (): bool => true);
        $response = file_get_contents($url);
        restore_error_handler();

        return is_string($response) ? $response : null;
    }
}
