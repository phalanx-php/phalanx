<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Template\Slice;

use DateTimeImmutable;

class ConversationSlice
{
    /**
     * @param list<ConversationMessage> $messages
     */
    public function __construct(
        private(set) array $messages = [],
        private(set) bool $isStreaming = false,
        private(set) string $thinkingBuffer = '',
        private(set) int $scrollOffset = 0,
        private(set) ?int $expandedIndex = null,
        private(set) bool $showThinking = false,
    ) {
    }

    public function addUserMessage(string $text): self
    {
        $message = new ConversationMessage(
            role: 'user',
            text: $text,
            channel: null,
            complete: true,
            at: new DateTimeImmutable(),
        );

        return new self(
            messages: [...$this->messages, $message],
            isStreaming: $this->isStreaming,
            thinkingBuffer: $this->thinkingBuffer,
            scrollOffset: 0,
            expandedIndex: null,
            showThinking: $this->showThinking,
        );
    }

    /**
     * @param 'message'|'thinking' $channel
     */
    public function appendToken(string $text, string $channel = 'message'): self
    {
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
                at: new DateTimeImmutable(),
            );
        }

        $thinkingBuffer = $channel === 'thinking'
            ? $this->thinkingBuffer . $text
            : $this->thinkingBuffer;

        return new self(
            messages: $messages,
            isStreaming: true,
            thinkingBuffer: $thinkingBuffer,
            scrollOffset: 0,
            expandedIndex: null,
            showThinking: $this->showThinking,
        );
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

        return new self(
            messages: $messages,
            isStreaming: false,
            thinkingBuffer: '',
            scrollOffset: $this->scrollOffset,
            expandedIndex: $this->expandedIndex,
            showThinking: $this->showThinking,
        );
    }

    public function scrollUp(): self
    {
        $max = max(0, count($this->exchangeIndexes()) - 1);

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

        $indexes = $this->exchangeIndexes();
        $index = count($indexes) - $this->scrollOffset;

        if (!isset($indexes[$index])) {
            return $this;
        }

        return new self(
            messages: $this->messages,
            isStreaming: $this->isStreaming,
            thinkingBuffer: $this->thinkingBuffer,
            scrollOffset: $this->scrollOffset,
            expandedIndex: $indexes[$index],
            showThinking: $this->showThinking,
        );
    }

    public function expandFocused(): self
    {
        $index = $this->focusedExchangeIndex();

        if ($index === null) {
            return $this;
        }

        return new self(
            messages: $this->messages,
            isStreaming: $this->isStreaming,
            thinkingBuffer: $this->thinkingBuffer,
            scrollOffset: $this->scrollOffset,
            expandedIndex: $index,
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
            isStreaming: $this->isStreaming,
            thinkingBuffer: $this->thinkingBuffer,
            scrollOffset: $this->scrollOffset,
            expandedIndex: $this->expandedIndex,
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

    private function withScroll(int $scrollOffset): self
    {
        return new self(
            messages: $this->messages,
            isStreaming: $this->isStreaming,
            thinkingBuffer: $this->thinkingBuffer,
            scrollOffset: $scrollOffset,
            expandedIndex: null,
            showThinking: $this->showThinking,
        );
    }
}
