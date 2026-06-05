<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Conversation\Record;

use Phalanx\AiProviders\Conversation\Record;
use Phalanx\AiProviders\Conversation\RecordType;

/**
 * A branch off the main conversation — a sub-agent conversation, a
 * thinking chain, or a tool-internal dialogue. `parentId` references the
 * record that spawned this branch; `branch` is a stable label (e.g.
 * `'thinking'`, `'sub-agent:planner'`). `summary` is an optional
 * human-readable digest of the branch's conclusion.
 */
final class Sidechain extends Record
{
    final public RecordType $type { get => RecordType::Sidechain; }

    public function __construct(
        string $id,
        ?int $sequence,
        \DateTimeImmutable $at,
        private(set) string $parentId,
        private(set) string $branch,
        private(set) ?string $summary = null,
    ) {
        parent::__construct($id, $sequence, $at);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function payload(): array
    {
        return [
            'parent_id' => $this->parentId,
            'branch' => $this->branch,
            'summary' => $this->summary,
        ];
    }
}
