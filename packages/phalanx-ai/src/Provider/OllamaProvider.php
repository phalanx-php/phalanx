<?php

declare(strict_types=1);

namespace Phalanx\Ai\Provider;

use Phalanx\Ai\Event\AgentEvent;
use Phalanx\Ai\Event\TokenDelta;
use Phalanx\Ai\Event\TokenUsage;
use Phalanx\Stream\Emitter;
use React\Http\Browser;
use React\Promise\Deferred;
use React\Stream\ReadableStreamInterface;

final class OllamaProvider implements LlmProvider
{
    private Browser $browser;

    public function __construct(
        private readonly OllamaConfig $config,
    ) {
        $this->browser = new Browser()
            ->withTimeout(300.0)
            ->withFollowRedirects(false);
    }

    public function generate(GenerateRequest $request): Emitter
    {
        $config = $this->config;
        $browser = $this->browser;

        return Emitter::produce(static function ($channel, $ctx) use ($request, $config, $browser) {
            $model = $request->model ?? $config->model;
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
            ];

            $startTime = hrtime(true);
            $step = 0;
            $usage = TokenUsage::zero();

            $response = $ctx->await($browser->requestStreaming(
                'POST',
                $config->baseUrl . '/api/chat',
                ['Content-Type' => 'application/json'],
                json_encode($body, JSON_THROW_ON_ERROR),
            ));

            /** @var ReadableStreamInterface $body */
            $body = $response->getBody();
            $ctx->onDispose(static fn() => $body->close());

            $buffer = '';
            $ended = false;
            $waiting = null;
            $abandoned = false;

            $body->on('data', static function (string $data) use (&$buffer, &$waiting, &$abandoned): void {
                if ($abandoned) {
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
                if ($abandoned) {
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
                if ($abandoned) {
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
                    $ctx->throwIfCancelled();

                    while (($nlPos = strpos($buffer, "\n")) !== false) {
                        $line = substr($buffer, 0, $nlPos);
                        $buffer = substr($buffer, $nlPos + 1);

                        if ($line === '') {
                            continue;
                        }

                        $parsed = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                        $elapsed = (hrtime(true) - $startTime) / 1e6;

                        $content = $parsed['message']['content'] ?? '';

                        if ($content !== '') {
                            $channel->emit(AgentEvent::tokenDelta(
                                new TokenDelta(text: $content),
                                $elapsed, $usage, $step,
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

                    if (!$ended) {
                        $waiting = new Deferred();
                        $ctx->await($waiting->promise());
                    }
                }
            } finally {
                $abandoned = true;
                $waiting = null;
            }

            $channel->complete();
        });
    }
}
