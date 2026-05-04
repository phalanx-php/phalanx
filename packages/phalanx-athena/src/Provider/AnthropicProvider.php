<?php

declare(strict_types=1);

namespace Phalanx\Athena\Provider;

use Phalanx\Athena\Event\AgentEvent;
use Phalanx\Athena\Event\TokenDelta;
use Phalanx\Athena\Event\TokenUsage;
use Phalanx\Athena\Event\ToolCallData;
use Phalanx\Athena\Stream\HttpSseSource;
use Phalanx\Styx\Emitter;
use Phalanx\System\HttpClient;
use Phalanx\System\HttpRequest;
use RuntimeException;

/**
 * Anthropic Claude streaming provider.
 *
 * Speaks Anthropic's `/v1/messages` SSE endpoint over HTTP/2 via the
 * Aegis-managed {@see HttpClient}. The body of `__invoke` reads top-to-
 * bottom as a synchronous coroutine: open stream, iterate SSE events
 * via {@see HttpSseSource}, dispatch `AgentEvent`s onto the producer's
 * channel. No promise ceremony, no callback-based stream consumption.
 *
 * The HttpClient is injected (test doubles for canned-stream coverage,
 * production constructed from {@see AnthropicConfig::baseUrl}).
 */
final class AnthropicProvider implements LlmProvider
{
    private readonly HttpClient $client;

    public function __construct(
        private readonly AnthropicConfig $config,
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
            $headers = self::buildHeaders($config);
            $startTime = hrtime(true);
            $step = 0;
            $usage = TokenUsage::zero();

            $jsonBody = json_encode($body, JSON_THROW_ON_ERROR);
            $httpRequest = new HttpRequest('POST', '/v1/messages', $jsonBody, $headers);
            $stream = $client->stream($ctx, $httpRequest);
            $ctx->onDispose(static fn() => $stream->close());

            $source = new HttpSseSource($stream);
            $accumulatedText = '';
            $currentToolId = null;
            $currentToolName = null;
            $currentToolInput = '';

            foreach ($source->events($ctx) as $sseEvent) {
                $ctx->throwIfCancelled();
                $data = $sseEvent['data'];
                if ($data === '[DONE]') {
                    break;
                }

                $parsed = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($parsed)) {
                    continue;
                }
                $type = (string) ($parsed['type'] ?? '');
                $elapsed = (hrtime(true) - $startTime) / 1e6;

                if ($stream->status >= 400) {
                    throw new RuntimeException("Anthropic API {$stream->status}: {$data}");
                }

                match ($type) {
                    'message_start' => self::onMessageStart($parsed, $usage),
                    'content_block_start' => self::onContentBlockStart(
                        $parsed,
                        $currentToolId,
                        $currentToolName,
                        $currentToolInput,
                        $channel,
                        $elapsed,
                        $usage,
                        $step,
                    ),
                    'content_block_delta' => self::onContentBlockDelta(
                        $parsed,
                        $accumulatedText,
                        $currentToolInput,
                        $channel,
                        $elapsed,
                        $usage,
                        $step,
                    ),
                    'content_block_stop' => self::onContentBlockStop(
                        $currentToolId,
                        $currentToolName,
                        $currentToolInput,
                        $channel,
                        $elapsed,
                        $usage,
                        $step,
                    ),
                    'message_delta' => self::onMessageDelta($parsed, $usage),
                    'message_stop' => $channel->emit(AgentEvent::tokenComplete($elapsed, $usage, $step)),
                    default => null,
                };
            }

            $channel->complete();
        });
    }

    private static function buildClient(AnthropicConfig $config): HttpClient
    {
        $parts = parse_url($config->baseUrl);
        $host = (string) ($parts['host'] ?? 'api.anthropic.com');
        $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? 'https') === 'https' ? 443 : 80));
        $tls = ($parts['scheme'] ?? 'https') === 'https';
        return new HttpClient($host, $port, tls: $tls);
    }

    /** @return array<string, mixed> */
    private static function buildRequestBody(GenerateRequest $request, string $model): array
    {
        $body = [
            'model' => $model,
            'max_tokens' => $request->maxTokens,
            'stream' => true,
        ];

        if ($request->conversation->systemPrompt !== null) {
            $body['system'] = $request->conversation->systemPrompt;
        }

        $body['messages'] = $request->conversation->toArray();

        if ($request->tools !== []) {
            $body['tools'] = $request->tools;
        }

        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }

        if ($request->stopSequences !== null) {
            $body['stop_sequences'] = $request->stopSequences;
        }

        return $body;
    }

    /** @return array<string, string> */
    private static function buildHeaders(AnthropicConfig $config): array
    {
        return [
            'content-type' => 'application/json',
            'x-api-key' => $config->apiKey,
            'anthropic-version' => $config->apiVersion,
            'accept' => 'text/event-stream',
        ];
    }

    /** @param array<string, mixed> $parsed */
    private static function onMessageStart(array $parsed, TokenUsage &$usage): void
    {
        $message = $parsed['message'] ?? [];
        $u = is_array($message) ? ($message['usage'] ?? []) : [];
        $usage = new TokenUsage(
            input: (int) (is_array($u) ? ($u['input_tokens'] ?? 0) : 0),
            output: 0,
        );
    }

    /** @param array<string, mixed> $parsed */
    private static function onContentBlockStart(
        array $parsed,
        ?string &$currentToolId,
        ?string &$currentToolName,
        string &$currentToolInput,
        mixed $channel,
        float $elapsed,
        TokenUsage $usage,
        int $step,
    ): void {
        $block = $parsed['content_block'] ?? [];
        if (!is_array($block) || ($block['type'] ?? '') !== 'tool_use') {
            return;
        }
        $currentToolId = (string) ($block['id'] ?? '');
        $currentToolName = (string) ($block['name'] ?? '');
        $currentToolInput = '';
        $channel->emit(AgentEvent::toolCallStart(
            new ToolCallData($currentToolId, $currentToolName),
            $elapsed,
            $usage,
            $step,
        ));
    }

    /** @param array<string, mixed> $parsed */
    private static function onContentBlockDelta(
        array $parsed,
        string &$accumulatedText,
        string &$currentToolInput,
        mixed $channel,
        float $elapsed,
        TokenUsage $usage,
        int $step,
    ): void {
        $delta = $parsed['delta'] ?? [];
        if (!is_array($delta)) {
            return;
        }
        $deltaType = (string) ($delta['type'] ?? '');

        if ($deltaType === 'text_delta') {
            $text = (string) ($delta['text'] ?? '');
            $accumulatedText .= $text;
            $channel->emit(AgentEvent::tokenDelta(new TokenDelta(text: $text), $elapsed, $usage, $step));
        } elseif ($deltaType === 'input_json_delta') {
            $currentToolInput .= (string) ($delta['partial_json'] ?? '');
        }
    }

    private static function onContentBlockStop(
        ?string &$currentToolId,
        ?string &$currentToolName,
        string &$currentToolInput,
        mixed $channel,
        float $elapsed,
        TokenUsage $usage,
        int $step,
    ): void {
        if ($currentToolId === null) {
            return;
        }
        $args = $currentToolInput !== ''
            ? json_decode($currentToolInput, true, 512, JSON_THROW_ON_ERROR)
            : [];
        $channel->emit(AgentEvent::toolCallComplete(
            new ToolCallData($currentToolId, $currentToolName ?? '', is_array($args) ? $args : []),
            $elapsed,
            $usage,
            $step,
        ));
        $currentToolId = null;
        $currentToolName = null;
        $currentToolInput = '';
    }

    /** @param array<string, mixed> $parsed */
    private static function onMessageDelta(array $parsed, TokenUsage &$usage): void
    {
        $u = $parsed['usage'] ?? [];
        if (!is_array($u)) {
            return;
        }
        $usage = new TokenUsage(
            input: $usage->input,
            output: (int) ($u['output_tokens'] ?? $usage->output),
        );
    }
}
