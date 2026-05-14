<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Fixtures;

use Phalanx\Athena\Event\AgentEvent;
use Phalanx\Athena\Event\TokenDelta;
use Phalanx\Athena\Event\TokenUsage;
use Phalanx\Athena\Event\ToolCallData;
use Phalanx\Athena\Provider\GenerateRequest;
use Phalanx\Athena\Provider\LlmProvider;
use Phalanx\Styx\Channel;
use Phalanx\Styx\Emitter;

final class MockProvider implements LlmProvider
{
    /** @var list<array{text: string, toolCalls: list<array{id: string, name: string, arguments: array<string, mixed>}>}> */
    private array $responses = [];

    private int $callIndex = 0;

    /** @param list<array{id: string, name: string, arguments: array<string, mixed>}> $toolCalls */
    public function addResponse(string $text, array $toolCalls = []): self
    {
        $this->responses[] = ['text' => $text, 'toolCalls' => $toolCalls];
        return $this;
    }

    public function generate(GenerateRequest $request): Emitter
    {
        $response = $this->responses[$this->callIndex]
            ?? $this->responses[array_key_last($this->responses)]
            ?? ['text' => '', 'toolCalls' => []];
        $this->callIndex++;

        return Emitter::produce(static function (Channel $ch) use ($response): void {
            $usage = new TokenUsage(input: 10, output: 5);

            foreach (str_split($response['text']) as $char) {
                $ch->emit(AgentEvent::tokenDelta(new TokenDelta(text: $char), 0.0, $usage, 0));
            }

            foreach ($response['toolCalls'] as $tc) {
                $ch->emit(AgentEvent::toolCallStart(
                    new ToolCallData($tc['id'], $tc['name']),
                    0.0,
                    $usage,
                    0,
                ));
                $ch->emit(AgentEvent::toolCallComplete(
                    new ToolCallData($tc['id'], $tc['name'], $tc['arguments']),
                    0.0,
                    $usage,
                    0,
                ));
            }

            $ch->emit(AgentEvent::tokenComplete(0.0, $usage, 0));
        });
    }

    public function callCount(): int
    {
        return $this->callIndex;
    }
}
