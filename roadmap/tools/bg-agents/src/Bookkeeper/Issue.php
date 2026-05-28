<?php

declare(strict_types=1);

namespace BgAgents\Bookkeeper;

final readonly class Issue
{
    /**
     * @param list<int> $refs            observation ids the issue references
     * @param array<string, mixed> $payload  kind-specific extra data (e.g. summary, distinct_facts)
     */
    public function __construct(
        public string $id,
        public IssueKind $kind,
        public array $refs,
        public string $suggestion,
        public array $payload = [],
    ) {}

    public static function duplicate(string $fingerprint, int $previousId, int $currentId): self
    {
        return new self(
            id: "dup-" . substr(sha1($fingerprint . ':' . $currentId), 0, 12),
            kind: IssueKind::Duplicate,
            refs: [$previousId, $currentId],
            suggestion: "observation {$currentId} duplicates {$previousId} (fingerprint {$fingerprint}); consider deduplication upstream",
        );
    }
}
