<?php

declare(strict_types=1);

namespace Phalanx\Athena\Stream;

use Phalanx\Athena\Event\AgentEvent;
use Phalanx\Athena\Event\AgentEventKind;
use Phalanx\Athena\Event\TokenUsage;
use Phalanx\Athena\Tool\ToolCall;
use Phalanx\Athena\Tool\ToolCallBag;
use Phalanx\Scope\Stream\StreamContext;
use Phalanx\Styx\Emitter;

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
