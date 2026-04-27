<?php

declare(strict_types=1);

namespace Phalanx\Athena\Memory;

use Phalanx\Athena\Message\Conversation;

interface ConversationMemory
{
    public function load(string $sessionId): Conversation;

    public function save(string $sessionId, Conversation $conversation): void;
}
