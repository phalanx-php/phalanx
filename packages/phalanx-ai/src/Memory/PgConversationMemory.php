<?php

declare(strict_types=1);

namespace Phalanx\Ai\Memory;

use Phalanx\Ai\Message\Conversation;
use Phalanx\Postgres\PgPool;

final class PgConversationMemory implements ConversationMemory
{
    public function __construct(
        private readonly PgPool $pg,
        private readonly string $table = 'conversations',
    ) {}

    public function load(string $sessionId): Conversation
    {
        $rows = $this->pg->execute(
            "SELECT messages FROM {$this->table} WHERE session_id = $1 LIMIT 1",
            [$sessionId],
        );

        if ($rows->getRowCount() === 0) {
            return Conversation::create();
        }

        $row = $rows->fetchRow();

        if ($row === null || !isset($row['messages']) || !is_string($row['messages'])) {
            return Conversation::create();
        }

        $messages = json_decode($row['messages'], true, 512, JSON_THROW_ON_ERROR);

        return Conversation::fromArray($messages);
    }

    public function save(string $sessionId, Conversation $conversation): void
    {
        $data = json_encode($conversation->toArray(), JSON_THROW_ON_ERROR);

        $this->pg->execute(
            "INSERT INTO {$this->table} (session_id, messages, updated_at)
             VALUES ($1, $2, NOW())
             ON CONFLICT (session_id) DO UPDATE
             SET messages = $2, updated_at = NOW()",
            [$sessionId, $data],
        );
    }
}
