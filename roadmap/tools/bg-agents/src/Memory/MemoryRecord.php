<?php

declare(strict_types=1);

namespace BgAgents\Memory;

/**
 * A high-quality, factual entry in the long-term memory layer.
 *
 * Distinct from a transient observation: memory records are atemporal
 * statements ("The player lock is globally exclusive") rather than status
 * reports. They survive for the life of daemon8's store and are surfaced
 * as RAG context to specialists on every relevant query.
 */
final readonly class MemoryRecord
{
    /**
     * @param list<string> $tags
     * @param list<int> $supersedes  observation ids of older memories this one replaces
     * @param list<int> $sourceObservations  raw obs ids that motivated this memory
     */
    public function __construct(
        public string $topic,
        public string $summary,
        public array $tags,
        public array $supersedes = [],
        public array $sourceObservations = [],
        public ?int $createdAtNs = null,
        public ?int $observationId = null,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload, ?int $observationId = null): self
    {
        $tags = $payload['tags'] ?? [];
        $supersedes = $payload['supersedes'] ?? [];
        $sources = $payload['source_observations'] ?? [];

        return new self(
            topic: is_string($payload['topic'] ?? null) ? $payload['topic'] : '',
            summary: is_string($payload['summary'] ?? null) ? $payload['summary'] : '',
            tags: is_array($tags) ? array_values(array_filter($tags, is_string(...))) : [],
            supersedes: is_array($supersedes) ? array_values(array_filter($supersedes, is_int(...))) : [],
            sourceObservations: is_array($sources) ? array_values(array_filter($sources, is_int(...))) : [],
            createdAtNs: isset($payload['created_at_ns']) && is_int($payload['created_at_ns'])
                ? $payload['created_at_ns']
                : null,
            observationId: $observationId,
        );
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'topic' => $this->topic,
            'summary' => $this->summary,
            'tags' => $this->tags,
            'supersedes' => $this->supersedes,
            'source_observations' => $this->sourceObservations,
            'created_at_ns' => $this->createdAtNs ?? (int) (microtime(true) * 1e9),
        ];
    }
}
