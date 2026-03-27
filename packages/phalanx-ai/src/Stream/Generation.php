<?php

declare(strict_types=1);

namespace Phalanx\Ai\Stream;

use Phalanx\Ai\Event\AgentEvent;
use Phalanx\Ai\Event\AgentEventKind;
use Phalanx\Ai\Event\TokenUsage;
use Phalanx\Ai\Tool\ToolCall;
use Phalanx\Ai\Tool\ToolCallBag;
use Phalanx\Stream\Contract\StreamContext;
use Phalanx\Stream\Emitter;

final readonly class Generation
{
    public function __construct(
        public string $text,
        public ToolCallBag $toolCalls,
        public TokenUsage $usage,
    ) {}

    public static function collect(Emitter $events, StreamContext $ctx, ?callable $onEvent = null): self
    {
        $text = '';
        $toolCalls = [];
        $usage = TokenUsage::zero();

        foreach ($events($ctx) as $event) {
            if (!$event instanceof AgentEvent) {
                continue;
            }

            if ($onEvent !== null) {
                $onEvent($event);
            }

            match ($event->kind) {
                AgentEventKind::TokenDelta => $text .= $event->data->text ?? '',
                AgentEventKind::ToolCallComplete => $toolCalls[] = new ToolCall(
                    id: $event->data->callId,
                    name: $event->data->toolName,
                    arguments: $event->data->arguments,
                ),
                AgentEventKind::TokenComplete => $usage = $event->usageSoFar,
                default => null,
            };
        }

        return new self($text, new ToolCallBag($toolCalls), $usage);
    }
}
