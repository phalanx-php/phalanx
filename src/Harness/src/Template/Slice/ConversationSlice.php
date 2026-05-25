<?php

declare(strict_types=1);

namespace Phalanx\Harness\Template\Slice;

use DateTimeImmutable;
use Phalanx\Athena\Persistence\EffectLogRecord;
use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\Activity\Cancelled as ActivityCancelled;
use Phalanx\Panoply\Cue\Activity\Completed as ActivityCompleted;
use Phalanx\Panoply\Cue\Activity\Failed as ActivityFailed;
use Phalanx\Panoply\Cue\Activity\Started as ActivityStarted;
use Phalanx\Panoply\Cue\Effect\Denied as EffectDenied;
use Phalanx\Panoply\Cue\Effect\Executed as EffectExecuted;
use Phalanx\Panoply\Cue\Effect\Failed as EffectFailed;
use Phalanx\Panoply\Cue\Effect\Requested as EffectRequested;
use Phalanx\Panoply\Cue\Invocation\Cancelled as InvocationCancelled;
use Phalanx\Panoply\Cue\Invocation\Completed as InvocationCompleted;
use Phalanx\Panoply\Cue\Invocation\Failed as InvocationFailed;
use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Panoply\Grant;

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
    ) {
    }

    /**
     * @param list<ConversationTurn> $turns
     */
    public static function fromTurns(
        array $turns,
        bool $isStreaming = false,
    ): self {
        $messages = [];

        foreach ($turns as $turn) {
            $messages[] = new ConversationMessage(
                role: 'user',
                text: $turn->userText,
                channel: null,
                complete: true,
                at: $turn->at,
            );

            if ($turn->thinkingText() !== '') {
                $messages[] = new ConversationMessage(
                    role: 'assistant',
                    text: $turn->thinkingText(),
                    channel: 'thinking',
                    complete: true,
                    at: $turn->at,
                );
            }

            if ($turn->assistantText() !== '') {
                $messages[] = new ConversationMessage(
                    role: 'assistant',
                    text: $turn->assistantText(),
                    channel: 'message',
                    complete: true,
                    at: $turn->at,
                );
            }
        }

        return new self(
            messages: $messages,
            turns: $turns,
            isStreaming: $isStreaming,
        );
    }

    public function addUserMessage(string $text): self
    {
        $at = new DateTimeImmutable();
        $turn = new ConversationTurn(
            id: $this->nextTurnId(),
            userText: $text,
            at: $at,
        );
        $message = new ConversationMessage(
            role: 'user',
            text: $text,
            channel: null,
            complete: true,
            at: $at,
        );

        return new self(
            messages: [...$this->messages, $message],
            turns: [...$this->turns, $turn],
            isStreaming: $this->isStreaming,
            thinkingBuffer: $this->thinkingBuffer,
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
            $status = self::statusForStopReason($cue->reason);

            return $this->appendEvent($event, $status)
                ->finalizeMessage($status);
        }

        if ($status !== null && self::isTerminalStatus($status)) {
            return $this->appendEvent($event, $status)
                ->finalizeMessage($status);
        }

        return $this->appendEvent($event, $status);
    }

    public function appendEffectLog(EffectLogRecord $record): self
    {
        return $this->appendEvent(ConversationTurnEvent::fromEffectLog($record), null);
    }

    public function appendGrant(Grant $grant, ?DateTimeImmutable $at = null): self
    {
        return $this->appendEvent(ConversationTurnEvent::fromGrant($grant, $at), null);
    }

    public function finalizeMessage(ConversationTurnStatus $status = ConversationTurnStatus::Completed): self
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
        $activeIndex = self::activeTurnIndex($turns);

        if ($activeIndex !== null) {
            $turns[$activeIndex] = $turns[$activeIndex]->withStatus($status);
        }

        return new self(
            messages: $messages,
            turns: array_values($turns),
            isStreaming: false,
            thinkingBuffer: '',
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
            $cue instanceof EffectExecuted => ConversationTurnStatus::Running,
            $cue instanceof EffectDenied => ConversationTurnStatus::Running,
            $cue instanceof EffectFailed => ConversationTurnStatus::Running,
            $cue instanceof ActivityCompleted => ConversationTurnStatus::Completed,
            $cue instanceof ActivityFailed => ConversationTurnStatus::Failed,
            $cue instanceof ActivityCancelled => ConversationTurnStatus::Cancelled,
            $cue instanceof InvocationCompleted => self::statusForStopReason($cue->stopReason),
            $cue instanceof InvocationFailed => ConversationTurnStatus::Failed,
            $cue instanceof InvocationCancelled => ConversationTurnStatus::Cancelled,
            default => null,
        };
    }

    private static function statusForStopReason(StopReason $reason): ConversationTurnStatus
    {
        return match ($reason) {
            StopReason::Error => ConversationTurnStatus::Failed,
            StopReason::Cancelled => ConversationTurnStatus::Cancelled,
            StopReason::ToolUse => ConversationTurnStatus::Running,
            default => ConversationTurnStatus::Completed,
        };
    }

    private static function isTerminalStatus(ConversationTurnStatus $status): bool
    {
        return match ($status) {
            ConversationTurnStatus::Completed,
            ConversationTurnStatus::Failed,
            ConversationTurnStatus::Cancelled => true,
            ConversationTurnStatus::Running,
            ConversationTurnStatus::AwaitingApproval => false,
        };
    }

    /**
     * @param list<ConversationTurn> $turns
     */
    private static function canAppendToLastTurn(array $turns, ConversationTurnEvent $event): bool
    {
        return $turns !== []
            && !$event->cue instanceof ActivityStarted
            && !$event->cue instanceof TokenDelta;
    }

    /**
     * @param list<ConversationTurn> $turns
     */
    private static function activeTurnIndex(array $turns): ?int
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
        );

        return $next->appendEvent($event, $status);
    }

    private function appendEvent(ConversationTurnEvent $event, ?ConversationTurnStatus $status): self
    {
        $turns = $this->turns;
        $activeIndex = self::activeTurnIndex($turns);

        if ($activeIndex === null && self::canAppendToLastTurn($turns, $event)) {
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
        );
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
