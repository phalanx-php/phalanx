<?php

declare(strict_types=1);

namespace AegisSwoole\Llm;

/**
 * Local Ollama defaults. Ollama exposes an OpenAI-compatible endpoint at
 * `/v1/chat/completions` on port 11434 by default, accepting the same JSON
 * payload (`model`, `messages`, `temperature`, `max_tokens`) and returning
 * the same `choices[0].message.content` shape, so a single client implementation
 * covers both Ollama and OpenAI-compatible providers.
 *
 * `apiKey` is optional — Ollama doesn't require one. When empty, the client
 * omits the Authorization header.
 */
final readonly class LlmConfig
{
    public function __construct(
        public string $apiKey = '',
        public string $host = '127.0.0.1',
        public int $port = 11434,
        public bool $ssl = false,
        public string $path = '/v1/chat/completions',
        public float $timeoutSeconds = 120.0,
    ) {
    }
}
