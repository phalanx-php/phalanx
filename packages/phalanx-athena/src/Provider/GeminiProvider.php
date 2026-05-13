<?php

declare(strict_types=1);

namespace Phalanx\Athena\Provider;

use Phalanx\Athena\Event\TokenUsage;
use Phalanx\Athena\Http\Url;
use Phalanx\Athena\Stream\HttpSseSource;
use Phalanx\Iris\HttpClient;
use Phalanx\Iris\HttpRequest;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Styx\Channel;
use Phalanx\Styx\Emitter;
use RuntimeException;

/**
 * Google Gemini streaming provider.
 *
 * Hits `/v1beta/models/{model}:streamGenerateContent?alt=sse&key=...`
 * through Iris outbound HTTP. The response is
 * an SSE stream where each `data:` line is a full JSON envelope; we
 * accumulate per-part text, surface token usage from `usageMetadata`
 * on the trailing chunks, and emit one `tokenComplete` at end-of-stream.
 */
final class GeminiProvider implements LlmProvider
{
    public function __construct(
        private readonly GeminiConfig $config,
        private readonly HttpClient $client,
    ) {
    }

    public function generate(GenerateRequest $request): Emitter
    {
        $config = $this->config;
        $client = $this->client;

        return Emitter::produce(static function (
            Channel $channel,
            ExecutionScope $ctx,
        ) use (
            $request,
            $config,
            $client,
        ): void {
            $pool = new StreamEventPool();
            $model = $request->model ?? $config->model;
            $body = self::buildRequestBody($request);
            $startTime = hrtime(true);
            $step = 0;
            $usage = TokenUsage::zero();

            $jsonBody = json_encode($body, JSON_THROW_ON_ERROR);
            $endpoint = sprintf(
                '/v1beta/models/%s:streamGenerateContent?alt=sse&key=%s',
                rawurlencode($model),
                rawurlencode($config->apiKey),
            );
            $httpRequest = HttpRequest::post(Url::join($config->baseUrl, $endpoint), $jsonBody, [
                'content-type' => ['application/json'],
                'accept' => ['text/event-stream'],
            ]);

            $stream = $client->stream($ctx, $httpRequest);
            $ctx->onDispose(static fn() => $stream->close());

            $source = new HttpSseSource($stream);

            foreach ($source->events($ctx) as $sseEvent) {
                $ctx->throwIfCancelled();
                $data = $sseEvent['data'];
                if ($data === '' || $data === '[DONE]') {
                    continue;
                }

                if ($stream->status >= 400) {
                    throw new RuntimeException("Gemini API {$stream->status} for model {$model}: {$data}");
                }

                $parsed = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($parsed)) {
                    continue;
                }
                $elapsed = (hrtime(true) - $startTime) / 1e6;

                self::onUsage($parsed, $usage);
                self::onCandidates($parsed, $channel, $pool, $elapsed, $usage, $step);
            }

            $finalElapsed = (hrtime(true) - $startTime) / 1e6;
            $channel->emit($pool->tokenComplete($finalElapsed, $usage, $step));
            $channel->complete();
        });
    }

    /** @return array<string, mixed> */
    private static function buildRequestBody(GenerateRequest $request): array
    {
        $contents = [];
        foreach ($request->conversation->messages as $msg) {
            $contents[] = [
                'role' => $msg->role->value === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $msg->text]],
            ];
        }

        if ($contents === []) {
            $contents[] = ['role' => 'user', 'parts' => [['text' => 'Hello']]];
        }

        $body = ['contents' => $contents];

        if ($request->conversation->systemPrompt !== null) {
            $body['system_instruction'] = ['parts' => [['text' => $request->conversation->systemPrompt]]];
        }

        return $body;
    }

    /** @param array<string, mixed> $parsed */
    private static function onUsage(array $parsed, TokenUsage &$usage): void
    {
        $meta = $parsed['usageMetadata'] ?? null;
        if (!is_array($meta)) {
            return;
        }
        $usage = new TokenUsage(
            input: (int) ($meta['promptTokenCount'] ?? $usage->input),
            output: (int) ($meta['candidatesTokenCount'] ?? $usage->output),
        );
    }

    /** @param array<string, mixed> $parsed */
    private static function onCandidates(
        array $parsed,
        Channel $channel,
        StreamEventPool $pool,
        float $elapsed,
        TokenUsage $usage,
        int $step,
    ): void {
        $candidates = $parsed['candidates'] ?? [];
        if (!is_array($candidates)) {
            return;
        }
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $parts = $candidate['content']['parts'] ?? [];
            if (!is_array($parts)) {
                continue;
            }
            foreach ($parts as $part) {
                if (!is_array($part)) {
                    continue;
                }
                $text = $part['text'] ?? null;
                if (!is_string($text) || $text === '') {
                    continue;
                }
                $channel->emit($pool->tokenDelta($text, $elapsed, $usage, $step));
            }
        }
    }
}
