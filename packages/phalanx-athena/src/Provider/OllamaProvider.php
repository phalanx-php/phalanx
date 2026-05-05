<?php

declare(strict_types=1);

namespace Phalanx\Athena\Provider;

use Phalanx\Athena\Event\AgentEvent;
use Phalanx\Athena\Event\TokenDelta;
use Phalanx\Athena\Event\TokenUsage;
use Phalanx\Athena\Http\Url;
use Phalanx\Iris\HttpClient;
use Phalanx\Iris\HttpRequest;
use Phalanx\Scope\Stream\StreamContext;
use Phalanx\Styx\Channel;
use Phalanx\Styx\Emitter;
use RuntimeException;

/**
 * Ollama local-LLM streaming provider.
 *
 * Talks NDJSON to `/api/chat` through Iris outbound HTTP. Each newline-
 * delimited JSON object is one incremental message frame; the final
 * frame carries `done: true` plus `prompt_eval_count` / `eval_count`
 * for token accounting.
 *
 * Uses {@see HttpStream::lines()} directly; no SSE parser needed.
 */
final class OllamaProvider implements LlmProvider
{
    public function __construct(
        private readonly OllamaConfig $config,
        private readonly HttpClient $client = new HttpClient(),
    ) {
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

    public function generate(GenerateRequest $request): Emitter
    {
        $config = $this->config;
        $client = $this->client;

        return Emitter::produce(static function (
            Channel $channel,
            StreamContext $ctx,
        ) use (
            $request,
            $config,
            $client,
        ): void {
            $model = $request->model ?? $config->model;
            $body = self::buildRequestBody($request, $model);
            $startTime = hrtime(true);
            $step = 0;
            $usage = TokenUsage::zero();

            $jsonBody = json_encode($body, JSON_THROW_ON_ERROR);
            $httpRequest = HttpRequest::post(Url::join($config->baseUrl, '/api/chat'), $jsonBody, [
                'content-type' => ['application/json'],
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
}
