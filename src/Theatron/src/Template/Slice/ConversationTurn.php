<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Template\Slice;

use DateTimeImmutable;
use Phalanx\Panoply\Cue\Output\Channel;

class ConversationTurn
{
    /**
     * @param list<ConversationTurnEvent> $events
     */
    public function __construct(
        private(set) string $id,
        private(set) string $userText,
        private(set) DateTimeImmutable $at,
        private(set) ConversationTurnStatus $status = ConversationTurnStatus::Running,
        private(set) array $events = [],
    ) {
    }

    public function withEvent(ConversationTurnEvent $event): self
    {
        return new self(
            id: $this->id,
            userText: $this->userText,
            at: $this->at,
            status: $this->status,
            events: [...$this->events, $event],
        );
    }

    public function withStatus(ConversationTurnStatus $status): self
    {
        return new self(
            id: $this->id,
            userText: $this->userText,
            at: $this->at,
            status: $status,
            events: $this->events,
        );
    }

    public function assistantText(): string
    {
        return $this->textFor(Channel::Message);
    }

    public function thinkingText(): string
    {
        $text = '';

        foreach ($this->events as $event) {
            if ($event->channel === Channel::Thinking || $event->channel === Channel::Reasoning) {
                $text .= $event->text;
            }
        }

        return $text;
    }

    public function hasAssistantText(): bool
    {
        return $this->assistantText() !== '';
    }

    public function hasThinkingText(): bool
    {
        return $this->thinkingText() !== '';
    }

    /**
     * @return list<ConversationTurnEvent>
     */
    public function projectionEvents(): array
    {
        return array_values(array_filter(
            $this->events,
            static fn(ConversationTurnEvent $event): bool => $event->projection->rendersInThread(),
        ));
    }

    private function textFor(Channel $channel): string
    {
        $text = '';

        foreach ($this->events as $event) {
            if ($event->channel === $channel) {
                $text .= $event->text;
            }
        }

        return $text;
    }
}
