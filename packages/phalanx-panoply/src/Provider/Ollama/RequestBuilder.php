<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider\Ollama;

use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Provider\Config\Model;
use Phalanx\Panoply\Transport\Request;

/**
 * Builds an Ollama Chat API {@see Request} from a panoply {@see Invocation}.
 * Static-only — no state, no lifecycle.
 *
 * Ollama is an auth-free local provider; no Authorization header is sent.
 * Messages are derived from `dynamicContext['messages']` when present.
 * Otherwise the builder prepends system instructions (when non-empty) and
 * wraps `dynamicContext['user_input']` as a single user turn.
 *
 * Final — sealed static utility; extension is neither needed nor safe.
 */
final class RequestBuilder
{
    private function __construct()
    {
    }

    public static function build(
        Invocation $invocation,
        Model $model,
        string $baseUrl,
        ChatOptions $options = new ChatOptions(),
    ): Request {
        $messages = self::deriveMessages($invocation);

        $body = [
            'model'    => $model->modelId,
            'stream'   => true,
            'messages' => $messages,
        ];

        $optionsBody = self::buildOptions($options);
        if ($optionsBody !== []) {
            $body['options'] = $optionsBody;
        }

        $tools = self::deriveTools($invocation);
        if ($tools !== []) {
            $body['tools'] = $tools;
        }

        return Request::of(
            method: 'POST',
            url: rtrim($baseUrl, '/') . '/api/chat',
            headers: [
                'content-type' => 'application/json',
                'accept'       => 'application/x-ndjson',
            ],
            body: json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private static function deriveMessages(Invocation $invocation): array
    {
        $history = $invocation->dynamicContext['messages'] ?? null;
        if (is_array($history) && $history !== []) {
            return array_values($history);
        }

        $messages = [];

        if ($invocation->instructions !== '') {
            $messages[] = ['role' => 'system', 'content' => $invocation->instructions];
        }

        $userInput = $invocation->dynamicContext['user_input']
            ?? $invocation->dynamicContext['user']
            ?? '';

        $messages[] = ['role' => 'user', 'content' => (string) $userInput];

        return $messages;
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildOptions(ChatOptions $options): array
    {
        $body = [];

        if ($options->temperature !== null) {
            $body['temperature'] = $options->temperature;
        }

        if ($options->numPredict !== null) {
            $body['num_predict'] = $options->numPredict;
        }

        if ($options->topP !== null) {
            $body['top_p'] = $options->topP;
        }

        if ($options->stop !== []) {
            $body['stop'] = array_values($options->stop);
        }

        return $body;
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
