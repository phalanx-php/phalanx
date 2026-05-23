<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider\OpenAI;

use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Provider\Config\Model;
use Phalanx\Panoply\Transport\Request;

/**
 * Builds an OpenAI Chat Completions {@see Request} from a panoply
 * {@see Invocation}. Static-only — no state, no lifecycle.
 *
 * Messages are derived from `dynamicContext['messages']` when present
 * (preferred: a pre-assembled conversation history). Otherwise the builder
 * wraps `dynamicContext['user_input']` as a single user turn. The
 * invocation `instructions` field becomes the first message with
 * `role: system` — OpenAI's Chat Completions API embeds system context
 * as a message, not a top-level field.
 *
 * Any `default_headers` from the provider config are merged into request
 * headers before the per-request authorization header, allowing
 * OpenRouter-style ranking headers or other static per-provider metadata.
 *
 * Final — sealed static utility; extension is neither needed nor safe.
 */
final class ChatRequestBuilder
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
        ChatOptions $options = new ChatOptions(),
        array $defaultHeaders = [],
    ): Request {
        $messages = self::deriveMessages($invocation);

        $body = [
            'model' => $model->modelId,
            'stream' => true,
            'messages' => $messages,
        ];

        $tools = self::deriveTools($invocation);
        if ($tools !== []) {
            $body['tools'] = $tools;
        }

        if ($options->maxTokens !== null) {
            $body['max_tokens'] = $options->maxTokens;
        }

        if ($options->temperature !== null) {
            $body['temperature'] = $options->temperature;
        }

        if ($options->topP !== null) {
            $body['top_p'] = $options->topP;
        }

        if ($options->stop !== []) {
            $body['stop'] = array_values($options->stop);
        }

        if ($options->seed !== null) {
            $body['seed'] = $options->seed;
        }

        $headers = array_merge($defaultHeaders, [
            'authorization' => 'Bearer ' . $apiKey,
            'content-type' => 'application/json',
            'accept' => 'text/event-stream',
        ]);

        return Request::of(
            method: 'POST',
            url: self::buildUrl($baseUrl, '/chat/completions'),
            headers: $headers,
            body: json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Derives the messages array. System instructions are prepended as the
     * first message when present. Conversation history comes from
     * `dynamicContext['messages']`; falling back to a single user turn from
     * `dynamicContext['user_input']`.
     *
     * @return list<array{role: string, content: string}>
     */
    private static function deriveMessages(Invocation $invocation): array
    {
        $messages = [];

        if ($invocation->instructions !== '') {
            $messages[] = ['role' => 'system', 'content' => $invocation->instructions];
        }

        $history = $invocation->dynamicContext['messages'] ?? null;
        if (is_array($history) && $history !== []) {
            return array_merge($messages, array_values($history));
        }

        $userInput = $invocation->dynamicContext['user_input']
            ?? $invocation->dynamicContext['user']
            ?? '';

        $messages[] = ['role' => 'user', 'content' => (string) $userInput];

        return $messages;
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
     * Constructs the full request URL from a base URL and an endpoint suffix.
     *
     * Convention: `$baseUrl` is the host of the canonical endpoint. The builder
     * always appends the full path from the host (`/v1/chat/completions`).
     * OpenAI-compatible providers whose YAML already contains `/v1` (e.g.
     * `https://api.together.xyz/v1`) are handled transparently: if the
     * normalized base URL already ends with `/v1`, only the suffix without
     * the `/v1` prefix is appended to avoid double-pathing.
     *
     * Examples:
     * - `https://api.openai.com`           → `https://api.openai.com/v1/chat/completions`
     * - `https://api.together.xyz/v1`      → `https://api.together.xyz/v1/chat/completions`
     * - `https://api.groq.com/openai/v1`   → `https://api.groq.com/openai/v1/chat/completions`
     */
    private static function buildUrl(string $baseUrl, string $suffix): string
    {
        $base = rtrim($baseUrl, '/');

        // If the base already ends with /v1 (or any /*/v1 variant), the caller
        // has pre-supplied the API root — append only the path segment after /v1.
        if (str_ends_with($base, '/v1')) {
            return $base . $suffix;
        }

        return $base . '/v1' . $suffix;
    }
}
