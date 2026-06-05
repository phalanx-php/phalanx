<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Provider\HuggingFace;

use Phalanx\AiProviders\Invocation;
use Phalanx\AiProviders\Provider\Config\Model;
use Phalanx\AiProviders\Transport\Request;

/**
 * Builds a Hugging Face Inference API {@see Request} from a ai-providers
 * {@see Invocation}. Static-only — no state, no lifecycle.
 *
 * The wire format is OpenAI Chat Completions-compatible. Standard fields
 * (`model`, `stream`, `messages`, `temperature`, `top_p`, `max_tokens`)
 * are set as in the OpenAI builder. HuggingFace-specific fields (`top_k`,
 * `do_sample`) are appended when non-null; the HuggingFace Inference API
 * accepts them as pass-through generation parameters.
 *
 * The Authorization header carries the API key as a Bearer token, matching
 * the Hugging Face token authentication convention.
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

        if ($options->temperature !== null) {
            $body['temperature'] = $options->temperature;
        }

        if ($options->topP !== null) {
            $body['top_p'] = $options->topP;
        }

        if ($options->topK !== null) {
            $body['top_k'] = $options->topK;
        }

        if ($options->maxNewTokens !== null) {
            $body['max_tokens'] = $options->maxNewTokens;
        }

        if ($options->doSample !== null) {
            $body['do_sample'] = $options->doSample;
        }

        $headers = array_merge($defaultHeaders, [
            'authorization' => 'Bearer ' . $apiKey,
            'content-type' => 'application/json',
            'accept' => 'text/event-stream',
        ]);

        return Request::of(
            method: 'POST',
            url: rtrim($baseUrl, '/') . '/v1/chat/completions',
            headers: $headers,
            body: json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
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
}
