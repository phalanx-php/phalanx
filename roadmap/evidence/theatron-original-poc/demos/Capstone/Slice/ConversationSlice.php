<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Capstone\Slice;

use Phalanx\Theatron\Store\Slice;

final class ConversationSlice implements Slice
{
    public string $key {
        get => 'capstone.conversation';
    }

    /**
     * @param list<ConversationMessage> $messages
     */
    public function __construct(
        private(set) array $messages = [],
    ) {
    }

    public function append(ConversationMessage $msg): self
    {
        $messages = $this->messages;
        $messages[] = $msg;

        if (count($messages) > 50) {
            $messages = array_slice($messages, -50);
        }

        return new self($messages);
    }
}
