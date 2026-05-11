<?php

declare(strict_types=1);

namespace Phalanx\Athena\Stream;

use Phalanx\Athena\AgentResult;
use Phalanx\Athena\Event\AgentEvent;
use Phalanx\Athena\Event\AgentEventKind;
use Phalanx\Athena\Message\Conversation;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Styx\Channel;
use Phalanx\Styx\Emitter;

final class TokenAccumulator
{
    private ?AgentResult $result = null;
    private ?Conversation $conversation = null;

    private function __construct(
        private readonly Emitter $events,
        private readonly Channel $textChannel,
    ) {
    }

    public static function from(Emitter $events, ExecutionScope $ctx): self
    {
        $channel = new Channel(bufferSize: 64);

        $accumulator = new self($events, $channel);

        // @dev-cleanup-ignore — background coroutine so from() returns immediately; callers stream via text()
        $ctx->go(static function (ExecutionScope $es) use ($accumulator): void {
            $accumulator->start($es);
        }, 'token-accumulator');

        return $accumulator;
    }

    public function text(): Emitter
    {
        $textChannel = $this->textChannel;

        return Emitter::produce(static function (Channel $ch) use ($textChannel): void {
            foreach ($textChannel->consume() as $text) {
                $ch->emit($text);
            }
            $ch->complete();
        });
    }

    public function events(): Emitter
    {
        return $this->events;
    }

    public function result(): AgentResult
    {
        if ($this->result === null) {
            throw new \RuntimeException('Agent has not completed yet');
        }

        return $this->result;
    }

    public function conversation(): Conversation
    {
        if ($this->conversation === null) {
            throw new \RuntimeException('Agent has not completed yet');
        }

        return $this->conversation;
    }

    private function start(ExecutionScope $scope): void
    {
        $ch = $this->textChannel;

        $emitter = $this->events;
        foreach ($emitter($scope) as $event) {
            if (!$event instanceof AgentEvent) {
                continue;
            }

            if ($event->kind === AgentEventKind::TokenDelta && $event->data->text !== null) {
                $ch->emit($event->data->text);
            }

            if ($event->kind === AgentEventKind::AgentComplete && $event->data instanceof AgentResult) {
                $this->result = $event->data;
                $this->conversation = $event->data->conversation;
            }
        }

        $ch->complete();
    }
}
