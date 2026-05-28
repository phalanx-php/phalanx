<?php

declare(strict_types=1);

namespace BgAgents\Specialist;

use BgAgents\Daemon8\ObservationClient;
use BgAgents\Daemon8\ObservationQuery;
use BgAgents\Daemon8\ObservationRecord;
use BgAgents\Memory\MemoryQuery;
use BgAgents\Memory\MemoryStore;
use Phalanx\ExecutionScope;

/**
 * Per-query ContextPack assembly.
 *
 * Concurrent fetches via $scope->concurrent() so observation pull and RAG
 * retrieval don't serialize. Either feed can fail without poisoning the
 * other (we render a placeholder line instead of throwing).
 */
final readonly class ContextPackBuilder
{
    public function __construct(
        public ObservationClient $client,
    ) {}

    public function build(ExecutionScope $scope, Specialist $specialist, string $prompt): ContextPack
    {
        $observationLines = self::fetchObservationLines($scope, $this->client, $specialist);
        $ragLines = self::fetchRagLines($scope, $specialist);

        return new ContextPack(
            specialist: $specialist,
            situational: $prompt,
            observationLines: $observationLines,
            ragLines: $ragLines,
        );
    }

    /** @return list<string> */
    private static function fetchObservationLines(
        ExecutionScope $scope,
        ObservationClient $client,
        Specialist $specialist,
    ): array {
        if ($specialist->subscription->isEmpty()) {
            return [];
        }

        $query = new ObservationQuery(
            kinds: $specialist->subscription->kinds,
            tags: $specialist->subscription->tags,
            origins: $specialist->subscription->origins,
            severityMin: $specialist->subscription->severityMin,
            limit: 30,
        );

        try {
            $result = $scope->await($client->observe($query));
        } catch (\Throwable) {
            return ['(observation fetch failed; daemon8 unreachable?)'];
        }

        $lines = [];
        foreach ($result['observations'] as $record) {
            $lines[] = self::summarize($record);
        }
        return $lines;
    }

    /** @return list<string> */
    private static function fetchRagLines(ExecutionScope $scope, Specialist $specialist): array
    {
        $store = $scope->service(MemoryStore::class);

        $records = $store->query($scope, new MemoryQuery(
            tags: $specialist->ragTags,
            topics: $specialist->ragTopics,
            limit: 8,
        ));

        if ($records === []) {
            return [];
        }

        $lines = [];
        foreach ($records as $r) {
            $lines[] = "{$r->topic}: {$r->summary}";
        }
        return $lines;
    }

    private static function summarize(ObservationRecord $record): string
    {
        $bgKind = $record->bgKind();
        $head = $bgKind ?? $record->kindTag;
        $origin = is_string($record->origin['name'] ?? null) ? $record->origin['name'] : 'unknown';
        $excerpt = self::excerptData($record->data);

        return "[{$record->id}] {$head} ({$origin}, {$record->severity}) — {$excerpt}";
    }

    /** @param array<string, mixed> $data */
    private static function excerptData(array $data): string
    {
        $serialized = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($serialized === false) {
            return '(unserializable)';
        }
        return mb_strlen($serialized) > 220 ? mb_substr($serialized, 0, 220) . '…' : $serialized;
    }
}
