<?php

declare(strict_types=1);

namespace Phalanx\Athena\Provider;

use Phalanx\Athena\Event\AgentEvent;
use Phalanx\Athena\Event\TokenDelta;
use Phalanx\Athena\Event\TokenUsage;
use Phalanx\Athena\Event\ToolCallData;
use Phalanx\Athena\Stream\SseParser;
use Phalanx\Styx\Emitter;
use React\Http\Browser;
use React\Stream\ReadableStreamInterface;

use React\Promise\Deferred;

use Phalanx\Stream\Contract\StreamContext;

final class AnthropicProvider implements LlmProvider
{
    private Browser $browser;

    public function __construct(
        private readonly AnthropicConfig $config,
    ) {
        $this->browser = new Browser()
            ->withTimeout(120.0)
            ->withFollowRedirects(false)
            ->withRejectErrorResponse(false);
    }

    public function generate(GenerateRequest $request): Emitter
    {
        $config = $this->config;
        $browser = $this->browser;

        return Emitter::produce(static function ($channel, $ctx) use ($request, $config, $browser) {
            $model = $request->model ?? $config->model;
            $body = self::buildRequestBody($request, $model, $config);
            $headers = self::buildHeaders($config);
            $startTime = hrtime(true);
            $step = 0;
            $usage = TokenUsage::zero();

            $jsonBody = json_encode($body, JSON_THROW_ON_ERROR);

            $response = $ctx->await($browser->requestStreaming(
                'POST',
                $config->baseUrl . '/v1/messages',
                $headers,
                $jsonBody,
            ));

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                $errStream = $response->getBody();
                $errBuf = '';
                if ($errStream instanceof ReadableStreamInterface) {
                    $errDone = new Deferred();
                    $errStream->on('data', static function (string $d) use (&$errBuf): void { $errBuf .= $d; });
                    $errStream->on('end', static function () use ($errDone): void { $errDone->resolve(null); });
                    $errStream->on('error', static function () use ($errDone): void { $errDone->resolve(null); });
                    $ctx->await($errDone->promise());
                }
                throw new \RuntimeException("Anthropic API {$statusCode}: {$errBuf}");
            }

            /** @var ReadableStreamInterface $body */
            $body = $response->getBody();
            $ctx->onDispose(static fn() => $body->close());

            $parser = new SseParser();
            $accumulatedText = '';
            $currentToolId = null;
            $currentToolName = null;
            $currentToolInput = '';

            foreach (self::readChunks($body, $ctx) as $chunk) {
                $ctx->throwIfCancelled();

                foreach ($parser->feed($chunk) as $sseEvent) {
                    $data = $sseEvent['data'];

                    if ($data === '[DONE]') {
                        break;
                    }

                    $parsed = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                    $type = $parsed['type'] ?? '';
                    $elapsed = (hrtime(true) - $startTime) / 1e6;

                    match ($type) {
                        'message_start' => (function () use (&$usage, $parsed) {
                            $u = $parsed['message']['usage'] ?? [];
                            $usage = new TokenUsage(
                                input: (int) ($u['input_tokens'] ?? 0),
                                output: 0,
                            );
                        })(),

                        'content_block_start' => (function () use ($parsed, &$currentToolId, &$currentToolName, &$currentToolInput, $channel, $elapsed, $usage, $step) {
                            $block = $parsed['content_block'] ?? [];
                            if (($block['type'] ?? '') === 'tool_use') {
                                $currentToolId = $block['id'] ?? '';
                                $currentToolName = $block['name'] ?? '';
                                $currentToolInput = '';
                                $channel->emit(AgentEvent::toolCallStart(
                                    new ToolCallData($currentToolId, $currentToolName),
                                    $elapsed, $usage, $step,
                                ));
                            }
                        })(),

                        'content_block_delta' => (function () use ($parsed, &$accumulatedText, &$currentToolInput, $channel, $elapsed, $usage, $step) {
                            $delta = $parsed['delta'] ?? [];
                            $deltaType = $delta['type'] ?? '';

                            if ($deltaType === 'text_delta') {
                                $text = $delta['text'] ?? '';
                                $accumulatedText .= $text;
                                $channel->emit(AgentEvent::tokenDelta(
                                    new TokenDelta(text: $text),
                                    $elapsed, $usage, $step,
                                ));
                            } elseif ($deltaType === 'input_json_delta') {
                                $currentToolInput .= $delta['partial_json'] ?? '';
                            }
                        })(),

                        'content_block_stop' => (function () use (&$currentToolId, &$currentToolName, &$currentToolInput, $channel, $elapsed, $usage, $step) {
                            if ($currentToolId !== null) {
                                $args = $currentToolInput !== ''
                                    ? json_decode($currentToolInput, true, 512, JSON_THROW_ON_ERROR)
                                    : [];
                                $channel->emit(AgentEvent::toolCallComplete(
                                    new ToolCallData($currentToolId, $currentToolName, $args),
                                    $elapsed, $usage, $step,
                                ));
                                $currentToolId = null;
                                $currentToolName = null;
                                $currentToolInput = '';
                            }
                        })(),

                        'message_delta' => (function () use (&$usage, $parsed) {
                            $u = $parsed['usage'] ?? [];
                            $usage = new TokenUsage(
                                input: $usage->input,
                                output: (int) ($u['output_tokens'] ?? $usage->output),
                            );
                        })(),

                        'message_stop' => $channel->emit(
                            AgentEvent::tokenComplete($elapsed, $usage, $step)
                        ),

                        default => null,
                    };
                }
            }

            $channel->complete();
        });
    }

    /** @return array<string, mixed> */
    private static function buildRequestBody(GenerateRequest $request, string $model, AnthropicConfig $config): array
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
            'Content-Type' => 'application/json',
            'x-api-key' => $config->apiKey,
            'anthropic-version' => $config->apiVersion,
        ];
    }

    /** @return \Generator<int, string, mixed, void> */
    private static function readChunks(ReadableStreamInterface $body, StreamContext $ctx): \Generator
    {
        $buffer = '';
        $ended = false;
        /** @var \React\Promise\Deferred<bool>|null $waiting */
        $waiting = null;
        $abandoned = false;

        $body->on('data', static function (string $data) use (&$buffer, &$waiting, &$abandoned): void {
            if ($abandoned) { // @phpstan-ignore if.alwaysFalse
                return;
            }
            $buffer .= $data;
            if ($waiting !== null) {
                $d = $waiting;
                $waiting = null;
                $d->resolve(true);
            }
        });

        $body->on('end', static function () use (&$ended, &$waiting, &$abandoned): void {
            if ($abandoned) { // @phpstan-ignore if.alwaysFalse
                return;
            }
            $ended = true;
            if ($waiting !== null) {
                $d = $waiting;
                $waiting = null;
                $d->resolve(false);
            }
        });

        $body->on('error', static function () use (&$ended, &$waiting, &$abandoned): void {
            if ($abandoned) { // @phpstan-ignore if.alwaysFalse
                return;
            }
            $ended = true;
            if ($waiting !== null) {
                $d = $waiting;
                $waiting = null;
                $d->resolve(false);
            }
        });

        try {
            while (!$ended || $buffer !== '') {
                if ($buffer !== '') {
                    $chunk = $buffer;
                    $buffer = '';
                    yield $chunk;
                } else {
                    $waiting = new Deferred();
                    $ctx->await($waiting->promise());
                }
            }
        } finally {
            $abandoned = true;
            $waiting = null;
        }
    }
}
