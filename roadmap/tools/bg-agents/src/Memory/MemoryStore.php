<?php

declare(strict_types=1);

namespace BgAgents\Memory;

use BgAgents\Daemon8\BgEvent;
use BgAgents\Daemon8\ObservationClient;
use BgAgents\Daemon8\ObservationQuery;
use BgAgents\Daemon8\ObservationRecord;
use Phalanx\Athena\Swarm\SwarmBus;
use Phalanx\Athena\Swarm\SwarmConfig;
use Phalanx\Athena\Swarm\SwarmEvent;
use Phalanx\Athena\Swarm\SwarmEventKind;
use Phalanx\ExecutionScope;

/**
 * RAG memory persistence on top of daemon8 observations.
 *
 * Writes are SwarmEvents (BlackboardPost, bg_kind=bg.memory.record). Reads
 * are filtered observation queries with the "bg.memory" tag. Superseded
 * records are filtered client-side: a record S whose id appears in any
 * other record's $supersedes is dropped from the result.
 *
 * Daemon8 is the source of truth — there is no local cache. If bg-agents
 * restarts, MemoryStore reads the same records back from the stream.
 */
final readonly class MemoryStore
{
    public function __construct(
        public ObservationClient $client,
        public SwarmBus $bus,
        public SwarmConfig $swarm,
    ) {}

    public function write(MemoryRecord $record): void
    {
        $payload = $record->toPayload();

        $this->bus->emit(new SwarmEvent(
            from: 'bookkeeper',
            kind: SwarmEventKind::BlackboardPost,
            workspace: $this->swarm->workspace,
            session: $this->swarm->session,
            payload: [
                'bg_kind' => BgEvent::MEMORY_RECORD,
                'topic' => $record->topic,
                'summary' => $record->summary,
                'tags' => $record->tags,
                'supersedes' => $record->supersedes,
                'source_observations' => $record->sourceObservations,
                'created_at_ns' => $payload['created_at_ns'],
            ],
        ));
    }

    /** @return list<MemoryRecord> */
    public function query(ExecutionScope $scope, MemoryQuery $q): array
    {
        try {
            $result = $scope->await($this->client->observe(new ObservationQuery(
                kinds: ['custom'],
                tags: ['bg.memory'],
                limit: 200,
            )));
        } catch (\Throwable) {
            return [];
        }

        $records = [];
        foreach ($result['observations'] as $rec) {
            if ($rec->bgKind() !== BgEvent::MEMORY_RECORD) {
                continue;
            }
            $payload = is_array($rec->data['payload'] ?? null) ? $rec->data['payload'] : [];
            $records[] = MemoryRecord::fromPayload($payload, $rec->id);
        }

        $records = self::dropSuperseded($records);
        $records = self::matchFilters($records, $q);

        return array_slice($records, 0, $q->limit);
    }

    /**
     * @param list<MemoryRecord> $records
     * @return list<MemoryRecord>
     */
    private static function dropSuperseded(array $records): array
    {
        $superseded = [];
        foreach ($records as $r) {
            foreach ($r->supersedes as $obsId) {
                $superseded[$obsId] = true;
            }
        }
        return array_values(array_filter(
            $records,
            static fn(MemoryRecord $r): bool => $r->observationId === null || !isset($superseded[$r->observationId]),
        ));
    }

    /**
     * @param list<MemoryRecord> $records
     * @return list<MemoryRecord>
     */
    private static function matchFilters(array $records, MemoryQuery $q): array
    {
        if ($q->tags === [] && $q->topics === []) {
            return $records;
        }

        return array_values(array_filter($records, static function (MemoryRecord $r) use ($q): bool {
            if ($q->tags !== []) {
                foreach ($q->tags as $tag) {
                    if (!in_array($tag, $r->tags, true)) {
                        return false;
                    }
                }
            }
            if ($q->topics !== []) {
                $matched = false;
                foreach ($q->topics as $topic) {
                    if (stripos($r->topic, $topic) !== false || stripos($r->summary, $topic) !== false) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    return false;
                }
            }
            return true;
        }));
    }

    /** @param list<ObservationRecord> $records */
    public static function debugCountInRecords(array $records): int
    {
        $n = 0;
        foreach ($records as $r) {
            if ($r->bgKind() === BgEvent::MEMORY_RECORD) {
                $n++;
            }
        }
        return $n;
    }
}
