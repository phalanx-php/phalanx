<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Conversation\Record;

use Phalanx\Panoply\Conversation\Record;
use Phalanx\Panoply\Conversation\RecordType;

/**
 * A file or binary object attached to a conversation turn. The
 * `attachmentId` is referenced by {@see Message::$attachments}. Size is
 * in bytes; `contentHash` is a SHA-256 hex string when present.
 */
final class Attachment extends Record
{
    final public RecordType $type { get => RecordType::Attachment; }

    public function __construct(
        string $id,
        ?int $sequence,
        \DateTimeImmutable $at,
        private(set) string $attachmentId,
        private(set) string $filename,
        private(set) string $mime,
        private(set) ?int $size = null,
        private(set) ?string $contentHash = null,
    ) {
        parent::__construct($id, $sequence, $at);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function payload(): array
    {
        return [
            'attachment_id' => $this->attachmentId,
            'filename'      => $this->filename,
            'mime'          => $this->mime,
            'size'          => $this->size,
            'content_hash'  => $this->contentHash,
        ];
    }
}
