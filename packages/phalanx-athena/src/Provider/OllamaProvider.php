<?php

declare(strict_types=1);

namespace Phalanx\Athena\Provider;

use Phalanx\Athena\Event\AgentEvent;
use Phalanx\Athena\Event\TokenDelta;
use Phalanx\Athena\Event\TokenUsage;
use Phalanx\Styx\Emitter;
use Phalanx\System\HttpClient;
use Phalanx\System\HttpRequest;
use RuntimeException;

/**
 * Ollama local-LLM streaming provider.
 *
 * Talks NDJSON to `/api/chat` over HTTP/2 (OpenSwoole 26's Http2 client
 * speaks h2c when TLS is off). Each newline-delimited JSON object is one
 * incremental message frame; the final frame carries `done: true` plus
 * `prompt_eval_count` / `eval_count` for token accounting.
 *
 * Uses {@see HttpStream::lines()} directly — no SSE parser needed.
 */
final class OllamaProvider implements LlmProvider
{
    private readonly HttpClient $client;

    public function __construct(
        private readonly OllamaConfig $config,
        ?HttpClient $client = null,
    ) {
        $this->client = $client ?? self::buildClient($config);
    }

    public function generate(GenerateRequest $request): Emitter
    {
        $config = $this->config;
        $client = $this->client;

        return Emitter::produce(static function ($channel, $ctx) use ($request, $config, $client): void {
            $model = $request->model ?? $config->model;
            $body = self::buildRequestBody($request, $model);
            $startTime = hrtime(true);
            $step = 0;
            $usage = TokenUsage::zero();

            $jsonBody = json_encode($body, JSON_THROW_ON_ERROR);
            $httpRequest = new HttpRequest('POST', '/api/chat', $jsonBody, [
                'content-type' => 'application/json',
            ]);

            $stream = $client->stream($ctx, $httpRequest);
            $ctx->onDispose(static fn() => $stream->close());

            foreach ($stream->lines($ctx) as $line) {
                $ctx->throwIfCancelled();
                if ($line === '') {
                    continue;
                }

                if ($stream->status >= 400) {
                    throw new RuntimeException("Ollama API {$stream->status}: {$line}");
                }

                $parsed = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($parsed)) {
                    continue;
                }
                $elapsed = (hrtime(true) - $startTime) / 1e6;

                $message = $parsed['message'] ?? [];
                $content = is_array($message) ? (string) ($message['content'] ?? '') : '';
                if ($content !== '') {
                    $channel->emit(AgentEvent::tokenDelta(
                        new TokenDelta(text: $content),
                        $elapsed,
                        $usage,
                        $step,
                    ));
                }

                if ($parsed['done'] ?? false) {
                    $usage = new TokenUsage(
                        input: (int) ($parsed['prompt_eval_count'] ?? 0),
                        output: (int) ($parsed['eval_count'] ?? 0),
                    );
                    $channel->emit(AgentEvent::tokenComplete($elapsed, $usage, $step));
                }
            }

            $channel->complete();
        });
    }

    private static function buildClient(OllamaConfig $config): HttpClient
    {
        $parts = parse_url($config->baseUrl);
        $host = (string) ($parts['host'] ?? 'localhost');
        $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? 'http') === 'https' ? 443 : 11434));
        $tls = ($parts['scheme'] ?? 'http') === 'https';
        return new HttpClient($host, $port, tls: $tls);
    }

    /** @return array<string, mixed> */
    private static function buildRequestBody(GenerateRequest $request, string $model): array
    {
        $messages = [];
        if ($request->conversation->systemPrompt !== null) {
            $messages[] = ['role' => 'system', 'content' => $request->conversation->systemPrompt];
        }
        foreach ($request->conversation->toArray() as $msg) {
            $messages[] = $msg;
        }

        return [
            'model' => $model,
            'messages' => $messages,
            'stream' => true,
        ];
    }
}
