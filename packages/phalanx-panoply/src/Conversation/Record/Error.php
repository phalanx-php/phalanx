<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Conversation\Record;

use Phalanx\Panoply\Conversation\Record;
use Phalanx\Panoply\Conversation\RecordType;

/**
 * An error encountered during the conversation — a failed tool call, a
 * parser error, or a runtime exception. `code` is a stable machine-readable
 * identifier; `message` is the human-readable description; `details` carries
 * arbitrary diagnostic context (stack trace fragments, request ids, etc.).
 */
final class Error extends Record
{
    final public RecordType $type { get => RecordType::Error; }

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $id,
        ?int $sequence,
        \DateTimeImmutable $at,
        private(set) string $code,
        private(set) string $message,
        private(set) array $details = [],
    ) {
        parent::__construct($id, $sequence, $at);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function payload(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'details' => $this->details,
        ];
    }
}
