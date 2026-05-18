<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Conversation\Record;

use Phalanx\Panoply\Conversation\Record;
use Phalanx\Panoply\Conversation\RecordType;

/**
 * A record the parser could not map to any known subclass. Only produced
 * when {@see \Phalanx\Panoply\Conversation\StrictMode::Lenient} is active.
 * `rawJson` is the verbatim JSON payload; `parserHint` is an optional
 * string the parser attaches to aid debugging (e.g. the raw kind string
 * it received from the source).
 */
final class Unknown extends Record
{
    final public RecordType $type { get => RecordType::Unknown; }

    public function __construct(
        string $id,
        ?int $sequence,
        \DateTimeImmutable $at,
        private(set) string $rawJson,
        private(set) ?string $parserHint = null,
    ) {
        parent::__construct($id, $sequence, $at);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function payload(): array
    {
        return [
            'raw_json'    => $this->rawJson,
            'parser_hint' => $this->parserHint,
        ];
    }
}
