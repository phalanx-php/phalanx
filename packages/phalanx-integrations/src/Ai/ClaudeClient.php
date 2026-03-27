<?php

declare(strict_types=1);

namespace Phalanx\Integration\Ai;

use Phalanx\Stream\Channel;
use Phalanx\Stream\Emitter;
use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use React\Stream\ReadableStreamInterface;

use function React\Async\await;

final class ClaudeClient
{
    private Browser $browser;

    public function __construct(private AiConfig $config)
    {
        $this->browser = new Browser()
            ->withTimeout(120.0)
            ->withFollowRedirects(false);
    }

    /**
     * @param list<ClaudeMessage> $messages
     * @param list<ToolDefinition> $tools
     * @return Emitter Yields ClaudeStreamChunk objects
     */
    public function stream(
        array $messages,
        ?string $system = null,
        array $tools = [],
        ?int $maxTokens = null,
        ?string $model = null,
    ): Emitter {
        $body = $this->buildBody($messages, $system, $tools, $maxTokens, $model);
        $body['stream'] = true;

        $headers = $this->buildHeaders();
        $encoded = json_encode($body, JSON_THROW_ON_ERROR);
        $browser = $this->browser;
        $endpoint = $this->config->claudeEndpoint;

        return Emitter::produce(static function (Channel $ch) use ($browser, $endpoint, $encoded, $headers): void {
            /** @var ResponseInterface $response */
            $response = await($browser->requestStreaming(
                'POST',
                $endpoint,
                $headers,
                $encoded,
            ));

            $stream = $response->getBody();
            assert($stream instanceof ReadableStreamInterface);

            $buffer = '';

            $stream->on('data', static function (string $data) use ($ch, $stream, &$buffer): void {
                $buffer .= $data;

                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $block = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);

                    $event = null;
                    $payload = null;

                    foreach (explode("\n", $block) as $line) {
                        if (str_starts_with($line, 'event: ')) {
                            $event = substr($line, 7);
                        } elseif (str_starts_with($line, 'data: ')) {
                            $payload = substr($line, 6);
                        }
                    }

                    if ($event === null || $payload === null) {
                        continue;
                    }

                    $decoded = json_decode($payload, true);
                    if (!is_array($decoded)) {
                        continue;
                    }

                    $chunk = ClaudeStreamChunk::fromSseEvent($event, $decoded);
                    if ($chunk !== null) {
                        $ch->emit($chunk);
                    }

                    if ($event === 'message_stop') {
                        $stream->close();
                        return;
                    }
                }
            });

            $stream->on('error', static function (\Throwable $e) use ($ch): void {
                $ch->error($e);
            });

            await(new \React\Promise\Promise(static function ($resolve) use ($stream): void {
                $stream->on('close', static fn() => $resolve(null));
            }));
        });
    }

    /**
     * @param list<ClaudeMessage> $messages
     * @param list<ToolDefinition> $tools
     */
    public function complete(
        array $messages,
        ?string $system = null,
        array $tools = [],
        ?int $maxTokens = null,
        ?string $model = null,
    ): string {
        $body = $this->buildBody($messages, $system, $tools, $maxTokens, $model);
        $headers = $this->buildHeaders();
        $encoded = json_encode($body, JSON_THROW_ON_ERROR);

        /** @var ResponseInterface $response */
        $response = await($this->browser->post(
            $this->config->claudeEndpoint,
            $headers,
            $encoded,
        ));

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        foreach ($data['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                return $block['text'];
            }
        }

        return '';
    }

    /**
     * @param list<ClaudeMessage> $messages
     * @param list<ToolDefinition> $tools
     * @return array<string, mixed>
     */
    private function buildBody(
        array $messages,
        ?string $system,
        array $tools,
        ?int $maxTokens,
        ?string $model,
    ): array {
        $body = [
            'model' => $model ?? $this->config->claudeModel,
            'max_tokens' => $maxTokens ?? $this->config->maxTokens,
            'messages' => array_map(static fn(ClaudeMessage $m) => $m->toArray(), $messages),
        ];

        if ($system !== null) {
            $body['system'] = $system;
        }

        if ($tools !== []) {
            $body['tools'] = array_map(static fn(ToolDefinition $t) => $t->toArray(), $tools);
        }

        return $body;
    }

    /** @return array<string, string> */
    private function buildHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'x-api-key' => $this->config->claudeApiKey,
            'anthropic-version' => '2023-06-01',
        ];
    }
}
