<?php

declare(strict_types=1);

namespace Phalanx\Athena\Stream;

use Closure;
use Phalanx\Athena\Event\AgentEvent;
use Phalanx\Athena\Event\AgentEventKind;
use Phalanx\Athena\Event\TokenUsage;
use Phalanx\Athena\Tool\ToolCall;
use Phalanx\Athena\Tool\ToolCallBag;
use Phalanx\Scope\Stream\StreamContext;
use Phalanx\Styx\Emitter;
use ReflectionFunction;
use RuntimeException;

final readonly class Generation
{
    public function __construct(
        public string $text,
        public ToolCallBag $toolCalls,
        public TokenUsage $usage,
    ) {
    }

    /** @param ?Closure(AgentEvent): void $onEvent */
    public static function collect(Emitter $events, StreamContext $ctx, ?Closure $onEvent = null): self
    {
        if ($onEvent !== null && !new ReflectionFunction($onEvent)->isStatic()) {
            throw new RuntimeException(
                'Generation::collect() $onEvent must be a static closure. Non-static '
                . 'closures capture $this and leak in long-running coroutines.',
            );
        }

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
