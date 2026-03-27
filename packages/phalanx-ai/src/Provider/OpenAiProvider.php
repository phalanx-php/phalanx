<?php

declare(strict_types=1);

namespace Phalanx\Ai\Provider;

use Phalanx\Ai\Event\AgentEvent;
use Phalanx\Ai\Event\TokenDelta;
use Phalanx\Ai\Event\TokenUsage;
use Phalanx\Ai\Event\ToolCallData;
use Phalanx\Ai\Stream\SseParser;
use Phalanx\Stream\Emitter;
use React\Http\Browser;
use React\Stream\ReadableStreamInterface;

use function React\Async\await;

final class OpenAiProvider implements LlmProvider
{
    private Browser $browser;

    public function __construct(
        private readonly OpenAiConfig $config,
    ) {
        $this->browser = new Browser()
            ->withTimeout(120.0)
            ->withFollowRedirects(false);
    }

    public function generate(GenerateRequest $request): Emitter
    {
        return Emitter::produce(function ($channel, $ctx) use ($request) {
            $model = $request->model ?? $this->config->model;
            $body = self::buildRequestBody($request, $model);
            $headers = self::buildHeaders($this->config);
            $startTime = hrtime(true);
            $step = 0;
            $usage = TokenUsage::zero();

            $response = await($this->browser->requestStreaming(
                'POST',
                $this->config->baseUrl . '/v1/chat/completions',
                $headers,
                json_encode($body, JSON_THROW_ON_ERROR),
            ));

            /** @var ReadableStreamInterface $body */
            $body = $response->getBody();
            $ctx->onDispose(static fn() => $body->close());

            $parser = new SseParser();
            $toolCalls = [];

            foreach (self::readChunks($body) as $chunk) {
                $ctx->throwIfCancelled();

                foreach ($parser->feed($chunk) as $sseEvent) {
                    $data = $sseEvent['data'];
                    if ($data === '[DONE]') {
                        break;
                    }

                    $parsed = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                    $choice = $parsed['choices'][0] ?? [];
                    $delta = $choice['delta'] ?? [];
                    $elapsed = (hrtime(true) - $startTime) / 1e6;

                    if (isset($parsed['usage'])) {
                        $usage = new TokenUsage(
                            input: (int) ($parsed['usage']['prompt_tokens'] ?? 0),
                            output: (int) ($parsed['usage']['completion_tokens'] ?? 0),
                        );
                    }

                    if (isset($delta['content']) && $delta['content'] !== '') {
                        $channel->emit(AgentEvent::tokenDelta(
                            new TokenDelta(text: $delta['content']),
                            $elapsed, $usage, $step,
                        ));
                    }

                    if (isset($delta['tool_calls'])) {
                        foreach ($delta['tool_calls'] as $tc) {
                            $idx = $tc['index'] ?? 0;
                            if (!isset($toolCalls[$idx])) {
                                $toolCalls[$idx] = [
                                    'id' => $tc['id'] ?? '',
                                    'name' => $tc['function']['name'] ?? '',
                                    'arguments' => '',
                                ];
                                $channel->emit(AgentEvent::toolCallStart(
                                    new ToolCallData($toolCalls[$idx]['id'], $toolCalls[$idx]['name']),
                                    $elapsed, $usage, $step,
                                ));
                            }
                            $toolCalls[$idx]['arguments'] .= $tc['function']['arguments'] ?? '';
                        }
                    }

                    if (($choice['finish_reason'] ?? null) !== null) {
                        foreach ($toolCalls as $tc) {
                            $args = $tc['arguments'] !== ''
                                ? json_decode($tc['arguments'], true, 512, JSON_THROW_ON_ERROR)
                                : [];
                            $channel->emit(AgentEvent::toolCallComplete(
                                new ToolCallData($tc['id'], $tc['name'], $args),
                                $elapsed, $usage, $step,
                            ));
                        }

                        $channel->emit(AgentEvent::tokenComplete($elapsed, $usage, $step));
                    }
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

        return $body;
    }

    /** @return array<string, string> */
    private static function buildHeaders(OpenAiConfig $config): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => "Bearer {$config->apiKey}",
        ];
    }

    /** @return \Generator<int, string, mixed, void> */
    private static function readChunks(ReadableStreamInterface $body): \Generator
    {
        $buffer = '';
        $ended = false;

        $body->on('data', static function (string $data) use (&$buffer): void {
            $buffer .= $data;
        });

        $body->on('end', static function () use (&$ended): void {
            $ended = true;
        });

        $body->on('error', static function () use (&$ended): void {
            $ended = true;
        });

        while (!$ended || $buffer !== '') {
            if ($buffer !== '') {
                $chunk = $buffer;
                $buffer = '';
                yield $chunk;
            } else {
                \React\EventLoop\Loop::futureTick(static fn() => null);
                \Fiber::suspend();
            }
        }
    }
}
