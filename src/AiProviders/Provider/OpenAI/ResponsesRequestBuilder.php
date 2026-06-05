<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Provider\OpenAI;

use Phalanx\AiProviders\Invocation;
use Phalanx\AiProviders\Provider\Config\Model;
use Phalanx\AiProviders\Transport\Request;

/**
 * Builds an OpenAI Responses API {@see Request} from a ai-providers
 * {@see Invocation}. Static-only — no state, no lifecycle.
 *
 * Input derivation prefers `dynamicContext['input']` (a pre-shaped Responses
 * input array or string). Falls back to `dynamicContext['user_input']` as a
 * plain string.
 *
 * Any `default_headers` from the provider config are merged into request
 * headers before the per-request authorization header.
 *
 * Final — sealed static utility; extension is neither needed nor safe.
 */
final class ResponsesRequestBuilder
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
        ResponsesOptions $options = new ResponsesOptions(),
        array $defaultHeaders = [],
    ): Request {
        $input = $invocation->dynamicContext['input']
            ?? $invocation->dynamicContext['user_input']
            ?? '';

        $body = [
            'model' => $model->modelId,
            'stream' => true,
            'input' => $input,
        ];

        if ($invocation->instructions !== '') {
            $body['instructions'] = $invocation->instructions;
        }

        $tools = self::deriveTools($invocation);
        if ($tools !== []) {
            $body['tools'] = $tools;
        }

        if ($options->reasoningEffort !== null) {
            $body['reasoning'] = ['effort' => $options->reasoningEffort];
        }

        if ($options->maxOutputTokens !== null) {
            $body['max_output_tokens'] = $options->maxOutputTokens;
        }

        if ($options->temperature !== null) {
            $body['temperature'] = $options->temperature;
        }

        if ($options->topP !== null) {
            $body['top_p'] = $options->topP;
        }

        $headers = array_merge($defaultHeaders, [
            'authorization' => 'Bearer ' . $apiKey,
            'content-type' => 'application/json',
            'accept' => 'text/event-stream',
        ]);

        return Request::of(
            method: 'POST',
            url: self::buildUrl($baseUrl, '/responses'),
            headers: $headers,
            body: json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
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
     * Constructs the full request URL. Mirrors the convention in
     * {@see ChatRequestBuilder::buildUrl()}: if $baseUrl already ends with
     * `/v1`, append only `/responses`; otherwise append `/v1/responses`.
     *
     * Examples:
     * - `https://api.openai.com`       → `https://api.openai.com/v1/responses`
     * - `https://api.openai.com/v1`    → `https://api.openai.com/v1/responses`
     */
    private static function buildUrl(string $baseUrl, string $suffix): string
    {
        $base = rtrim($baseUrl, '/');

        if (str_ends_with($base, '/v1')) {
            return $base . $suffix;
        }

        return $base . '/v1' . $suffix;
    }
}
