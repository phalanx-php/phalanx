<?php

declare(strict_types=1);

namespace Phalanx\Ai\Memory;

use Phalanx\Ai\Message\Conversation;

interface ConversationMemory
{
    public function load(string $sessionId): Conversation;

    public function save(string $sessionId, Conversation $conversation): void;
}
