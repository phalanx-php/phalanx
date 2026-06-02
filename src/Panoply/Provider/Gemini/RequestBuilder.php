<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider\Gemini;

use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Provider\Config\Model;
use Phalanx\Panoply\Transport\Request;

/**
 * Builds a Gemini Generative Language API streaming {@see Request} from a
 * panoply {@see Invocation}. Static-only — no state, no lifecycle.
 *
 * The API key is embedded in the URL query string (Gemini convention),
 * NOT in an Authorization header. The endpoint path includes the model ID:
 * `/v1beta/models/{modelId}:streamGenerateContent?alt=sse&key={apiKey}`.
 *
 * `contents` derivation: prefer `dynamicContext['contents']` (Gemini-shaped
 * array). Otherwise a single user turn is constructed from
 * `dynamicContext['user_input']`. The `instructions` field becomes the
 * top-level `systemInstruction` — Gemini's API separates system context
 * from the conversation history.
 *
 * Final — sealed static utility; extension is neither needed nor safe.
 */
final class RequestBuilder
{
    private function __construct()
    {
    }

    /**
     * @param array<string, string> $defaultHeaders
     */
    public static function build(
        Invocation $invocation,
        Model $model,
        string $apiKey,
        string $baseUrl,
        Options $options = new Options(),
        array $defaultHeaders = [],
    ): Request {
        $url = self::buildUrl($baseUrl, $model->modelId, $apiKey);
        $body = self::buildBody($invocation, $model, $options);

        $headers = array_merge($defaultHeaders, [
            'content-type' => 'application/json',
            'accept' => 'text/event-stream',
        ]);

        return Request::of(
            method: 'POST',
            url: $url,
            headers: $headers,
            body: json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    private static function buildUrl(string $baseUrl, string $modelId, string $apiKey): string
    {
        $base = rtrim($baseUrl, '/');

        return $base
            . '/v1beta/models/'
            . rawurlencode($modelId)
            . ':streamGenerateContent?alt=sse&key='
            . urlencode($apiKey);
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildBody(Invocation $invocation, Model $model, Options $options): array
    {
        $body = [
            'contents' => self::deriveContents($invocation),
        ];

        if ($invocation->instructions !== '') {
            $body['systemInstruction'] = [
                'parts' => [['text' => $invocation->instructions]],
            ];
        }

        $tools = self::deriveTools($invocation);
        if ($tools !== []) {
            $body['tools'] = [['functionDeclarations' => $tools]];
        }

        $generationConfig = self::buildGenerationConfig($options);
        if ($generationConfig !== []) {
            $body['generationConfig'] = $generationConfig;
        }

        return $body;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function deriveContents(Invocation $invocation): array
    {
        $contents = $invocation->dynamicContext['contents'] ?? null;
        if (is_array($contents) && $contents !== []) {
            return array_values($contents);
        }

        $userInput = $invocation->dynamicContext['user_input']
            ?? $invocation->dynamicContext['user']
            ?? '';

        return [
            [
                'role' => 'user',
                'parts' => [['text' => (string) $userInput]],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function deriveTools(Invocation $invocation): array
    {
        $tools = $invocation->dynamicContext['tools'] ?? [];

        return is_array($tools) ? array_values($tools) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildGenerationConfig(Options $options): array
    {
        $config = [];

        if ($options->maxOutputTokens !== null) {
            $config['maxOutputTokens'] = $options->maxOutputTokens;
        }

        if ($options->temperature !== null) {
            $config['temperature'] = $options->temperature;
        }

        if ($options->topP !== null) {
            $config['topP'] = $options->topP;
        }

        if ($options->topK !== null) {
            $config['topK'] = $options->topK;
        }

        if ($options->stopSequences !== []) {
            $config['stopSequences'] = array_values($options->stopSequences);
        }

        if ($options->thinkingBudget !== null) {
            $config['thinkingConfig'] = ['thinkingBudget' => self::mapThinkingBudget($options->thinkingBudget)];
        }

        return $config;
    }

    /**
     * Maps the panoply thinking-budget level to Gemini's integer thinkingBudget.
     *
     * These are approximate heuristic translations — Gemini 2.5 accepts any
     * non-negative integer. 0 disables thinking entirely. Higher values allow
     * more reasoning tokens at the cost of latency and quota.
     *
     * "low"    →  256   (minimal thinking; fast responses)
     * "medium" → 1024   (balanced default)
     * "high"   → 4096   (extended reasoning; slower, higher quality)
     */
    private static function mapThinkingBudget(string $level): int
    {
        return match ($level) {
            'low' => 256,
            'high' => 4096,
            default => 1024,
        };
    }
}
