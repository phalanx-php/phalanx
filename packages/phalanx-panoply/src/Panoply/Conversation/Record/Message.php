<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Conversation\Record;

use Phalanx\Panoply\Conversation\Record;
use Phalanx\Panoply\Conversation\RecordType;

/**
 * A single conversation turn — a user message, an assistant reply, or a
 * system prompt. Attachment ids reference any accompanying
 * {@see Attachment} records in the same {@see \Phalanx\Panoply\Conversation\Log}.
 */
final class Message extends Record
{
    final public RecordType $type { get => RecordType::Message; }

    /**
     * @param list<string> $attachments attachment record ids
     */
    public function __construct(
        string $id,
        ?int $sequence,
        \DateTimeImmutable $at,
        private(set) string $role,
        private(set) string $text,
        private(set) array $attachments = [],
    ) {
        parent::__construct($id, $sequence, $at);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function payload(): array
    {
        return [
            'role'        => $this->role,
            'text'        => $this->text,
            'attachments' => $this->attachments,
        ];
    }
}
