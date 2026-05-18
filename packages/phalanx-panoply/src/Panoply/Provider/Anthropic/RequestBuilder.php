<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider\Anthropic;

use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Provider\Config\Model;
use Phalanx\Panoply\Transport\Request;

/**
 * Builds an Anthropic-specific {@see Request} from a panoply
 * {@see Invocation}. Static-only — no state, no lifecycle.
 *
 * Messages are derived from `dynamicContext['messages']` when present
 * (preferred: a pre-assembled conversation history). Otherwise the builder
 * wraps `dynamicContext['user_input']` (or `dynamicContext['user']`) as a
 * single user turn.
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
        string $apiKey,
        string $baseUrl,
        Options $options = new Options(),
    ): Request {
        $body = [
            'model'      => $model->modelId,
            'max_tokens' => $options->maxTokens,
            'system'     => $invocation->instructions,
            'messages'   => self::deriveMessages($invocation),
            'stream'     => true,
        ];

        $tools = self::deriveTools($invocation);
        if ($tools !== []) {
            $body['tools'] = $tools;
        }

        return Request::of(
            method: 'POST',
            url: rtrim($baseUrl, '/') . '/v1/messages',
            headers: [
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
                'accept'            => 'text/event-stream',
            ],
            body: json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private static function deriveMessages(Invocation $invocation): array
    {
        $messages = $invocation->dynamicContext['messages'] ?? null;
        if (is_array($messages) && $messages !== []) {
            return array_values($messages);
        }

        $userInput = $invocation->dynamicContext['user_input']
            ?? $invocation->dynamicContext['user']
            ?? '';

        return [['role' => 'user', 'content' => (string) $userInput]];
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
