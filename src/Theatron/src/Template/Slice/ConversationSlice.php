<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Template\Slice;

use DateTimeImmutable;
use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\Activity\Cancelled as ActivityCancelled;
use Phalanx\Panoply\Cue\Activity\Completed as ActivityCompleted;
use Phalanx\Panoply\Cue\Activity\Failed as ActivityFailed;
use Phalanx\Panoply\Cue\Activity\Started as ActivityStarted;
use Phalanx\Panoply\Cue\Effect\Requested as EffectRequested;
use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;

class ConversationSlice
{
    /**
     * @param list<ConversationMessage> $messages
     * @param list<ConversationTurn> $turns
     */
    public function __construct(
        private(set) array $messages = [],
        private(set) array $turns = [],
        private(set) bool $isStreaming = false,
        private(set) string $thinkingBuffer = '',
        private(set) int $scrollOffset = 0,
        private(set) ?int $expandedIndex = null,
        private(set) ?string $selectedTurnId = null,
        private(set) bool $showThinking = false,
    ) {
    }

    public function addUserMessage(string $text): self
    {
        $turn = new ConversationTurn(
            id: $this->nextTurnId(),
            userText: $text,
            at: new DateTimeImmutable(),
        );
        $message = new ConversationMessage(
            role: 'user',
            text: $text,
            channel: null,
            complete: true,
            at: new DateTimeImmutable(),
        );

        return new self(
            messages: [...$this->messages, $message],
            turns: [...$this->turns, $turn],
            isStreaming: $this->isStreaming,
            thinkingBuffer: $this->thinkingBuffer,
            scrollOffset: 0,
            expandedIndex: null,
            selectedTurnId: null,
            showThinking: $this->showThinking,
        );
    }

    /**
     * @param 'message'|'thinking' $channel
     */
    public function appendToken(string $text, string $channel = 'message'): self
    {
        $eventChannel = $channel === 'thinking'
            ? Channel::Thinking
            : Channel::Message;
        $event = ConversationTurnEvent::token(
            id: $this->nextEventId(),
            at: new DateTimeImmutable(),
            text: $text,
            channel: $eventChannel,
        );

        return $this->appendTokenProjection(
            $text,
            $channel,
            $event,
            $eventChannel === Channel::Thinking ? ConversationTurnStatus::Running : null,
        );
    }

    public function appendCue(Cue $cue): self
    {
        $event = ConversationTurnEvent::fromCue($cue);
        $status = self::statusForCue($cue);

        if ($cue instanceof TokenDelta) {
            $channel = match ($cue->channel) {
                Channel::Message => 'message',
                Channel::Thinking, Channel::Reasoning => 'thinking',
            };

            return $this->appendTokenProjection($cue->text, $channel, $event, $status);
        }

        if ($cue instanceof TokenStop) {
            return $this->appendEvent($event, ConversationTurnStatus::Completed)
                ->finalizeMessage();
        }

        return $this->appendEvent($event, $status);
    }

    public function finalizeMessage(): self
    {
        $messages = $this->messages;

        if ($messages !== []) {
            $last = $messages[count($messages) - 1];
            $messages[count($messages) - 1] = new ConversationMessage(
                role: $last->role,
                text: $last->text,
                channel: $last->channel,
                complete: true,
                at: $last->at,
            );
        }

        $turns = $this->turns;
        $activeIndex = $this->activeTurnIndex($turns);

        if ($activeIndex !== null) {
            $turns[$activeIndex] = $turns[$activeIndex]->withStatus(ConversationTurnStatus::Completed);
        }

        return new self(
            messages: $messages,
            turns: array_values($turns),
            isStreaming: false,
            thinkingBuffer: '',
            scrollOffset: $this->scrollOffset,
            expandedIndex: $this->expandedIndex,
            selectedTurnId: $this->selectedTurnId,
            showThinking: $this->showThinking,
        );
    }

    public function selectedTurn(): ?ConversationTurn
    {
        if ($this->selectedTurnId === null) {
            return null;
        }

        foreach ($this->turns as $turn) {
            if ($turn->id === $this->selectedTurnId) {
                return $turn;
            }
        }

        return null;
    }

    public function focusedTurn(): ?ConversationTurn
    {
        if ($this->turns === []) {
            return null;
        }

        $index = count($this->turns) - 1 - $this->scrollOffset;

        return $this->turns[$index] ?? null;
    }

    public function scrollUp(): self
    {
        $max = max(0, count($this->turns) - 1);

        return $this->withScroll(min($max, $this->scrollOffset + 1));
    }

    public function scrollDown(): self
    {
        return $this->withScroll(max(0, $this->scrollOffset - 1));
    }

    public function expandAtScroll(): self
    {
        if ($this->scrollOffset === 0) {
            return $this;
        }

        $turn = $this->focusedTurn();

        if ($turn === null) {
            return $this;
        }

        return new self(
            messages: $this->messages,
            turns: $this->turns,
            isStreaming: $this->isStreaming,
            thinkingBuffer: $this->thinkingBuffer,
            scrollOffset: $this->scrollOffset,
            expandedIndex: $this->focusedExchangeIndex(),
            selectedTurnId: $turn->id,
            showThinking: $this->showThinking,
        );
    }

    public function expandFocused(): self
    {
        $turn = $this->focusedTurn();
        $index = $this->focusedExchangeIndex();

        if ($turn === null || $index === null) {
            return $this;
        }

        return new self(
            messages: $this->messages,
            turns: $this->turns,
            isStreaming: $this->isStreaming,
            thinkingBuffer: $this->thinkingBuffer,
            scrollOffset: $this->scrollOffset,
            expandedIndex: $index,
            selectedTurnId: $turn->id,
            showThinking: $this->showThinking,
        );
    }

    public function focusedExchangeIndex(): ?int
    {
        $indexes = $this->exchangeIndexes();

        if ($indexes === []) {
            return null;
        }

        $index = count($indexes) - 1 - $this->scrollOffset;

        return $indexes[$index] ?? null;
    }

    public function refocus(): self
    {
        return $this->withScroll(0);
    }

    public function toggleThinking(): self
    {
        return new self(
            messages: $this->messages,
            turns: $this->turns,
            isStreaming: $this->isStreaming,
            thinkingBuffer: $this->thinkingBuffer,
            scrollOffset: $this->scrollOffset,
            expandedIndex: $this->expandedIndex,
            selectedTurnId: $this->selectedTurnId,
            showThinking: !$this->showThinking,
        );
    }

    /**
     * @return list<int>
     */
    public function exchangeIndexes(): array
    {
        $indexes = [];

        foreach ($this->messages as $i => $message) {
            if ($message->role === 'user') {
                $indexes[] = $i;
            }
        }

        return $indexes;
    }

    private static function statusForCue(Cue $cue): ?ConversationTurnStatus
    {
        return match (true) {
            $cue instanceof EffectRequested && $cue->requiresApproval => ConversationTurnStatus::AwaitingApproval,
            $cue instanceof ActivityCompleted => ConversationTurnStatus::Completed,
            $cue instanceof ActivityFailed => ConversationTurnStatus::Failed,
            $cue instanceof ActivityCancelled => ConversationTurnStatus::Cancelled,
            default => null,
        };
    }

    private function withScroll(int $scrollOffset): self
    {
        return new self(
            messages: $this->messages,
            turns: $this->turns,
            isStreaming: $this->isStreaming,
            thinkingBuffer: $this->thinkingBuffer,
            scrollOffset: $scrollOffset,
            expandedIndex: null,
            selectedTurnId: null,
            showThinking: $this->showThinking,
        );
    }

    /** @param 'message'|'thinking' $channel */
    private function appendTokenProjection(
        string $text,
        string $channel,
        ConversationTurnEvent $event,
        ?ConversationTurnStatus $status,
    ): self {
        $messages = $this->messages;
        $last = $messages === [] ? null : $messages[count($messages) - 1];

        if ($last !== null && !$last->complete && $last->channel === $channel) {
            $updated = new ConversationMessage(
                role: 'assistant',
                text: $last->text . $text,
                channel: $channel,
                complete: false,
                at: $last->at,
            );
            $messages[count($messages) - 1] = $updated;
        } else {
            $messages[] = new ConversationMessage(
                role: 'assistant',
                text: $text,
                channel: $channel,
                complete: false,
                at: $event->at,
            );
        }

        $thinkingBuffer = $channel === 'thinking'
            ? $this->thinkingBuffer . $text
            : $this->thinkingBuffer;

        $next = new self(
            messages: $messages,
            turns: $this->turns,
            isStreaming: true,
            thinkingBuffer: $thinkingBuffer,
            scrollOffset: 0,
            expandedIndex: null,
            selectedTurnId: null,
            showThinking: $this->showThinking,
        );

        return $next->appendEvent($event, $status);
    }

    private function appendEvent(ConversationTurnEvent $event, ?ConversationTurnStatus $status): self
    {
        $turns = $this->turns;
        $activeIndex = $this->activeTurnIndex($turns);

        if ($activeIndex === null && $turns !== [] && !$event->cue instanceof ActivityStarted && !$event->cue instanceof TokenDelta) {
            $activeIndex = array_key_last($turns);
        }

        if ($activeIndex === null) {
            $turns[] = new ConversationTurn(
                id: $this->nextTurnId(),
                userText: '',
                at: $event->at,
            );
            $activeIndex = array_key_last($turns);
        }

        $turn = $turns[$activeIndex]->withEvent($event);
        $turns[$activeIndex] = $status === null ? $turn : $turn->withStatus($status);

        return new self(
            messages: $this->messages,
            turns: array_values($turns),
            isStreaming: $this->isStreaming,
            thinkingBuffer: $this->thinkingBuffer,
            scrollOffset: $this->scrollOffset,
            expandedIndex: $this->expandedIndex,
            selectedTurnId: $this->selectedTurnId,
            showThinking: $this->showThinking,
        );
    }

    /**
     * @param list<ConversationTurn> $turns
     */
    private function activeTurnIndex(array $turns): ?int
    {
        for ($i = count($turns) - 1; $i >= 0; $i--) {
            if ($turns[$i]->status === ConversationTurnStatus::Running) {
                return $i;
            }

            if ($turns[$i]->status === ConversationTurnStatus::AwaitingApproval) {
                return $i;
            }
        }

        return null;
    }

    private function nextTurnId(): string
    {
        return 'turn_' . (count($this->turns) + 1);
    }

    private function nextEventId(): string
    {
        $count = 1;

        foreach ($this->turns as $turn) {
            $count += count($turn->events);
        }

        return 'event_' . $count;
    }
}
