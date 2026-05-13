<?php

declare(strict_types=1);

namespace Phalanx\Athena\Provider;

use Phalanx\Athena\Event\TokenUsage;
use Phalanx\Athena\Event\ToolCallData;
use Phalanx\Athena\Http\Url;
use Phalanx\Athena\Stream\HttpSseSource;
use Phalanx\Iris\HttpClient;
use Phalanx\Iris\HttpRequest;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Styx\Channel;
use Phalanx\Styx\Emitter;
use RuntimeException;

/**
 * OpenAI Chat Completions streaming provider.
 *
 * Speaks the `/v1/chat/completions` SSE endpoint through Iris outbound
 * HTTP. Mirrors {@see AnthropicProvider} in
 * shape: synchronous-coroutine body, byte stream into {@see HttpSseSource},
 * match-dispatch on event payload type.
 *
 * OpenAI's stream is chunk-flavored: each SSE `data:` line carries a
 * `choices[0].delta` object with incremental `content`, `tool_calls`,
 * and an optional `finish_reason`. Token usage rides on a final chunk
 * when `stream_options.include_usage` is set.
 */
final class OpenAiProvider implements LlmProvider
{
    public function __construct(
        private readonly OpenAiConfig $config,
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
            $body = self::buildRequestBody($request, $model);
            $headers = self::buildHeaders($config);
            $startTime = hrtime(true);
            $step = 0;
            $usage = TokenUsage::zero();

            $jsonBody = json_encode($body, JSON_THROW_ON_ERROR);
            $httpRequest = HttpRequest::post(Url::join($config->baseUrl, '/v1/chat/completions'), $jsonBody, $headers);
            $stream = $client->stream($ctx, $httpRequest);
            $ctx->onDispose(static fn() => $stream->close());

            $source = new HttpSseSource($stream);
            $toolCalls = [];

            foreach ($source->events($ctx) as $sseEvent) {
                $ctx->throwIfCancelled();
                $data = $sseEvent['data'];
                if ($data === '[DONE]') {
                    break;
                }

                if ($stream->status >= 400) {
                    throw new RuntimeException("OpenAI API {$stream->status}: {$data}");
                }

                $parsed = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($parsed)) {
                    continue;
                }

                $elapsed = (hrtime(true) - $startTime) / 1e6;
                self::onUsage($parsed, $usage);

                $choice = $parsed['choices'][0] ?? [];
                if (!is_array($choice)) {
                    continue;
                }
                $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];

                self::onContentDelta($delta, $channel, $pool, $elapsed, $usage, $step);
                self::onToolCallDeltas($delta, $toolCalls, $channel, $pool, $elapsed, $usage, $step);

                if (($choice['finish_reason'] ?? null) !== null) {
                    self::onFinish($toolCalls, $channel, $pool, $elapsed, $usage, $step);
                }
            }

            $channel->complete();
        });
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

        $body = [
            'model' => $model,
            'messages' => $messages,
            'stream' => true,
            'stream_options' => ['include_usage' => true],
        ];

        if ($request->maxTokens > 0) {
            $body['max_tokens'] = $request->maxTokens;
        }

        if ($request->tools !== []) {
            $body['tools'] = array_map(static fn(array $tool) => [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['input_schema'],
                ],
            ], $request->tools);
        }

        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }

        if ($request->stopSequences !== null) {
            $body['stop'] = $request->stopSequences;
        }

        return $body;
    }

    /** @return array<string, list<string>> */
    private static function buildHeaders(OpenAiConfig $config): array
    {
        return [
            'content-type' => ['application/json'],
            'authorization' => ["Bearer {$config->apiKey}"],
            'accept' => ['text/event-stream'],
        ];
    }

    /** @param array<string, mixed> $parsed */
    private static function onUsage(array $parsed, TokenUsage &$usage): void
    {
        if (!isset($parsed['usage']) || !is_array($parsed['usage'])) {
            return;
        }
        $usage = new TokenUsage(
            input: (int) ($parsed['usage']['prompt_tokens'] ?? 0),
            output: (int) ($parsed['usage']['completion_tokens'] ?? 0),
        );
    }

    /** @param array<string, mixed> $delta */
    private static function onContentDelta(
        array $delta,
        Channel $channel,
        StreamEventPool $pool,
        float $elapsed,
        TokenUsage $usage,
        int $step,
    ): void {
        $content = $delta['content'] ?? '';
        if (!is_string($content) || $content === '') {
            return;
        }
        $channel->emit($pool->tokenDelta($content, $elapsed, $usage, $step));
    }

    /**
     * @param array<string, mixed> $delta
     * @param array<int, array{id: string, name: string, arguments: string}> $toolCalls
     */
    private static function onToolCallDeltas(
        array $delta,
        array &$toolCalls,
        Channel $channel,
        StreamEventPool $pool,
        float $elapsed,
        TokenUsage $usage,
        int $step,
    ): void {
        $deltaCalls = $delta['tool_calls'] ?? null;
        if (!is_array($deltaCalls)) {
            return;
        }
        foreach ($deltaCalls as $tc) {
            if (!is_array($tc)) {
                continue;
            }
            $idx = (int) ($tc['index'] ?? 0);
            $fn = is_array($tc['function'] ?? null) ? $tc['function'] : [];

            if (!isset($toolCalls[$idx])) {
                $toolCalls[$idx] = [
                    'id' => (string) ($tc['id'] ?? ''),
                    'name' => (string) ($fn['name'] ?? ''),
                    'arguments' => '',
                ];
                $channel->emit($pool->toolCallStart(
                    new ToolCallData($toolCalls[$idx]['id'], $toolCalls[$idx]['name']),
                    $elapsed,
                    $usage,
                    $step,
                ));
            }
            $toolCalls[$idx]['arguments'] .= (string) ($fn['arguments'] ?? '');
        }
    }

    /**
     * @param array<int, array{id: string, name: string, arguments: string}> $toolCalls
     */
    private static function onFinish(
        array $toolCalls,
        Channel $channel,
        StreamEventPool $pool,
        float $elapsed,
        TokenUsage $usage,
        int $step,
    ): void {
        foreach ($toolCalls as $tc) {
            $args = $tc['arguments'] !== ''
                ? json_decode($tc['arguments'], true, 512, JSON_THROW_ON_ERROR)
                : [];
            $channel->emit($pool->toolCallComplete(
                new ToolCallData($tc['id'], $tc['name'], is_array($args) ? $args : []),
                $elapsed,
                $usage,
                $step,
            ));
        }
        $channel->emit($pool->tokenComplete($elapsed, $usage, $step));
    }
}
