<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Conversation\Record;

use Phalanx\Panoply\Conversation\Record;
use Phalanx\Panoply\Conversation\RecordType;

/**
 * A scalar key-value annotation attached to the conversation. Use this for
 * tool-specific housekeeping (e.g. session id, cost center, model version)
 * that does not fit any richer record type. Richer structured payloads
 * deserve their own dedicated record subclass.
 */
final class Metadata extends Record
{
    final public RecordType $type { get => RecordType::Metadata; }

    public function __construct(
        string $id,
        ?int $sequence,
        \DateTimeImmutable $at,
        private(set) string $key,
        private(set) string|int|float|bool $value,
    ) {
        parent::__construct($id, $sequence, $at);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function payload(): array
    {
        return [
            'key'   => $this->key,
            'value' => $this->value,
        ];
    }
}
