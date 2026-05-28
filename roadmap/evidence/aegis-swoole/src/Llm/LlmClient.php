<?php

declare(strict_types=1);

namespace AegisSwoole\Llm;

use AegisSwoole\Cancellation\Cancelled;
use AegisSwoole\Scope\Suspendable;
use OpenSwoole\Coroutine\Http\Client;
use RuntimeException;

/**
 * Minimal OpenAI-compatible chat-completions client. POSTs JSON to
 * `$config->path` and parses `choices[0].message.content` out of the response.
 * Defaults target a local Ollama daemon (`127.0.0.1:11434/v1/chat/completions`)
 * but the same client works against OpenAI, OpenRouter, etc. by swapping
 * `LlmConfig`. Each call goes through `$scope->call(...)` so scope
 * cancellation propagates.
 */
class LlmClient
{
    public function __construct(
        private readonly Suspendable $scope,
        private readonly LlmConfig $config,
    ) {
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     */
    public function complete(string $model, array $messages, float $temperature = 0.7, int $maxTokens = 256): string
    {
        $config = $this->config;
        return $this->scope->call(static function () use ($model, $messages, $temperature, $maxTokens, $config): string {
            $client = new Client($config->host, $config->port, $config->ssl);
            $client->set([
                'timeout' => $config->timeoutSeconds,
                'ssl_verify_peer' => false,
            ]);
            $headers = [
                'Host' => $config->host,
                'Content-Type' => 'application/json',
            ];
            if ($config->apiKey !== '') {
                $headers['Authorization'] = "Bearer {$config->apiKey}";
            }
            $client->setHeaders($headers);
            $client->setMethod('POST');
            $client->setData((string) json_encode([
                'model' => $model,
                'messages' => $messages,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ], JSON_THROW_ON_ERROR));

            try {
                $ok = $client->execute($config->path);
                if ($ok === false) {
                    if ((int) $client->errCode === 0 && (int) $client->statusCode === 0) {
                        throw new Cancelled('llm request cancelled');
                    }
                    throw new RuntimeException(
                        "llm request failed: errCode={$client->errCode} statusCode={$client->statusCode}",
                    );
                }
                $body = (string) $client->body;
                if ((int) $client->statusCode >= 400) {
                    throw new RuntimeException("llm http {$client->statusCode}: " . substr($body, 0, 500));
                }
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded) || !isset($decoded['choices'][0]['message']['content'])) {
                    throw new RuntimeException('llm response missing choices[0].message.content: ' . substr($body, 0, 500));
                }
                return (string) $decoded['choices'][0]['message']['content'];
            } finally {
                $client->close();
            }
        });
    }
}
