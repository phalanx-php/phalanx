<?php

declare(strict_types=1);

namespace Phalanx\Ai\Stream;

use Phalanx\Ai\AgentResult;
use Phalanx\Ai\Event\AgentEvent;
use Phalanx\Ai\Event\AgentEventKind;
use Phalanx\Ai\Message\Conversation;
use Phalanx\Stream\Channel;
use Phalanx\Stream\Contract\StreamContext;
use Phalanx\Stream\Emitter;

final class TokenAccumulator
{
    private ?AgentResult $result = null;
    private ?Conversation $conversation = null;

    private function __construct(
        private readonly Emitter $events,
        private readonly Channel $textChannel,
        private readonly StreamContext $ctx,
    ) {}

    public static function from(Emitter $events, StreamContext $ctx): self
    {
        $channel = new Channel(bufferSize: 64);

        $accumulator = new self($events, $channel, $ctx);
        $accumulator->start();

        return $accumulator;
    }

    private function start(): void
    {
        $ch = $this->textChannel;

        $emitter = $this->events;
        foreach ($emitter($this->ctx) as $event) {
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

    public function text(): Emitter
    {
        $textChannel = $this->textChannel;

        return Emitter::produce(static function (Channel $ch) use ($textChannel) {
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
}
