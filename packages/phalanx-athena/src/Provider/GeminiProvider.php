<?php

declare(strict_types=1);

namespace Phalanx\Athena\Provider;

use Phalanx\Athena\Event\AgentEvent;
use Phalanx\Athena\Event\TokenDelta;
use Phalanx\Athena\Event\TokenUsage;
use Phalanx\Athena\Stream\SseParser;
use Phalanx\Styx\Emitter;
use React\Http\Browser;
use React\Stream\ReadableStreamInterface;
use React\Promise\Deferred;
use Phalanx\Stream\Contract\StreamContext;

final class GeminiProvider implements LlmProvider
{
    private Browser $browser;

    public function __construct(
        private readonly GeminiConfig $config,
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
            $body = self::buildRequestBody($request);
            $jsonBody = json_encode($body, JSON_THROW_ON_ERROR);
            
            $url = sprintf(
                '%s/v1beta/models/%s:streamGenerateContent?alt=sse&key=%s',
                $config->baseUrl,
                $model,
                $config->apiKey
            );

            $response = $ctx->await($browser->requestStreaming('POST', $url, ['Content-Type' => 'application/json'], $jsonBody));

            if ($response->getStatusCode() >= 400) {
                 $body = (string)$response->getBody();
                 throw new \RuntimeException("Gemini API Error {$response->getStatusCode()} for model {$model}: {$body}");
            }

            $bodyStream = $response->getBody();
            $parser = new SseParser();
            $usage = TokenUsage::zero();
            
            $bodyStream->on('data', static function (string $chunk) use ($channel, $parser, &$usage) {
                foreach ($parser->feed($chunk) as $event) {
                    $parsed = json_decode($event['data'], true);
                    if (!$parsed) continue;

                    $candidates = $parsed['candidates'] ?? [];
                    foreach ($candidates as $candidate) {
                        $parts = $candidate['content']['parts'] ?? [];
                        foreach ($parts as $part) {
                            if (isset($part['text'])) {
                                $channel->emit(AgentEvent::tokenDelta(new TokenDelta($part['text']), 0, $usage, 0));
                            }
                        }
                    }

                    if (isset($parsed['usageMetadata'])) {
                        $usage = new TokenUsage(
                            input: (int) ($parsed['usageMetadata']['promptTokenCount'] ?? $usage->input),
                            output: (int) ($parsed['usageMetadata']['candidatesTokenCount'] ?? $usage->output),
                        );
                    }
                }
            });

            $done = new Deferred();
            $bodyStream->on('end', static fn() => $done->resolve(null));
            $ctx->await($done->promise());
            
            $channel->emit(AgentEvent::tokenComplete(0, $usage, 0));
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
                'parts' => [['text' => $msg->text]]
            ];
        }

        // If contents is empty, we MUST add a user message
        if ($contents === []) {
            $contents[] = ['role' => 'user', 'parts' => [['text' => 'Hello']]];
        }

        $body = ['contents' => $contents];

        // For gemini-flash-lite, we try putting instructions in system_instruction
        // but if it still fails, we'll have to merge it into the first user message.
        if ($request->conversation->systemPrompt !== null) {
            $body['system_instruction'] = ['parts' => [['text' => $request->conversation->systemPrompt]]];
        }

        return $body;
    }
}
