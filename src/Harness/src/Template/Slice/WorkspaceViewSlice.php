<?php

declare(strict_types=1);

namespace Phalanx\Harness\Template\Slice;

use Phalanx\Theatron\Input\InputMode;
use Phalanx\Theatron\Input\InputModeSlice;

class WorkspaceViewSlice
{
    /** @param array<string, InputModeSlice> $inputModes */
    public function __construct(
        private(set) int $chatScrollOffset = 0,
        private(set) ?string $expandedTurnId = null,
        private(set) ?string $selectedTurnId = null,
        private(set) bool $showThinking = false,
        private(set) ?string $returnTarget = null,
        private(set) array $inputModes = [],
    ) {
    }

    public function focusedTurn(ConversationSlice $conversation): ?ConversationTurn
    {
        if ($conversation->turns === []) {
            return null;
        }

        $index = count($conversation->turns) - 1 - $this->chatScrollOffset;

        return $conversation->turns[$index] ?? null;
    }

    public function selectedTurn(ConversationSlice $conversation): ?ConversationTurn
    {
        if ($this->selectedTurnId === null) {
            return null;
        }

        foreach ($conversation->turns as $turn) {
            if ($turn->id === $this->selectedTurnId) {
                return $turn;
            }
        }

        return null;
    }

    public function scrollChatUp(ConversationSlice $conversation): self
    {
        $max = max(0, count($conversation->turns) - 1);

        return $this->withChatScroll(min($max, $this->chatScrollOffset + 1));
    }

    public function scrollChatDown(): self
    {
        return $this->withChatScroll(max(0, $this->chatScrollOffset - 1));
    }

    public function scrollChatToOldest(ConversationSlice $conversation): self
    {
        return $this->withChatScroll(max(0, count($conversation->turns) - 1));
    }

    public function refocusChat(): self
    {
        return $this->withChatScroll(0);
    }

    public function startChatTurn(): self
    {
        return new self(
            chatScrollOffset: 0,
            expandedTurnId: null,
            selectedTurnId: null,
            showThinking: $this->showThinking,
            returnTarget: null,
            inputModes: $this->inputModes,
        );
    }

    public function expandFocusedChatTurn(ConversationSlice $conversation): self
    {
        $turn = $this->focusedTurn($conversation);

        if ($turn === null) {
            return $this;
        }

        return new self(
            chatScrollOffset: $this->chatScrollOffset,
            expandedTurnId: $turn->id,
            selectedTurnId: $this->selectedTurnId,
            showThinking: $this->showThinking,
            returnTarget: $this->returnTarget,
            inputModes: $this->inputModes,
        );
    }

    public function selectFocusedChatTurn(ConversationSlice $conversation, ?string $returnTarget = null): self
    {
        $turn = $this->focusedTurn($conversation);

        if ($turn === null) {
            return $this;
        }

        return new self(
            chatScrollOffset: $this->chatScrollOffset,
            expandedTurnId: $this->expandedTurnId,
            selectedTurnId: $turn->id,
            showThinking: $this->showThinking,
            returnTarget: $returnTarget,
            inputModes: $this->inputModes,
        );
    }

    public function toggleThinking(): self
    {
        return new self(
            chatScrollOffset: $this->chatScrollOffset,
            expandedTurnId: $this->expandedTurnId,
            selectedTurnId: $this->selectedTurnId,
            showThinking: !$this->showThinking,
            returnTarget: $this->returnTarget,
            inputModes: $this->inputModes,
        );
    }

    public function withInputMode(string $workspace, InputMode $mode, ?string $focusTarget): self
    {
        $inputModes = $this->inputModes;
        $inputModes[$workspace] = new InputModeSlice($mode, $focusTarget);

        return new self(
            chatScrollOffset: $this->chatScrollOffset,
            expandedTurnId: $this->expandedTurnId,
            selectedTurnId: $this->selectedTurnId,
            showThinking: $this->showThinking,
            returnTarget: $this->returnTarget,
            inputModes: $inputModes,
        );
    }

    public function inputModeFor(string $workspace): ?InputModeSlice
    {
        return $this->inputModes[$workspace] ?? null;
    }

    private function withChatScroll(int $scrollOffset): self
    {
        return new self(
            chatScrollOffset: $scrollOffset,
            expandedTurnId: null,
            selectedTurnId: $this->selectedTurnId,
            showThinking: $this->showThinking,
            returnTarget: $this->returnTarget,
            inputModes: $this->inputModes,
        );
    }
}
