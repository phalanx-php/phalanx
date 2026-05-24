<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Conversation\Record;

use Phalanx\Panoply\Conversation\Record;
use Phalanx\Panoply\Conversation\RecordType;

/**
 * A point-in-time snapshot of a file from the agent's working tree. Used
 * by parsers that record file reads or writes verbatim for replay and audit.
 * `contentHash` is a SHA-256 hex string of `content`.
 */
final class FileSnapshot extends Record
{
    final public RecordType $type { get => RecordType::FileSnapshot; }

    public function __construct(
        string $id,
        ?int $sequence,
        \DateTimeImmutable $at,
        private(set) string $path,
        private(set) string $content,
        private(set) string $contentHash,
    ) {
        parent::__construct($id, $sequence, $at);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function payload(): array
    {
        return [
            'path' => $this->path,
            'content' => $this->content,
            'content_hash' => $this->contentHash,
        ];
    }
}
