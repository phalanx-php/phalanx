<?php

declare(strict_types=1);

namespace BgAgents\Repl\Handler;

use BgAgents\Daemon8\BgEvent;
use BgAgents\Daemon8\ObservationClient;
use BgAgents\Daemon8\ObservationQuery;
use BgAgents\Daemon8\ObservationRecord;
use BgAgents\Memory\MemoryRecord;
use BgAgents\Memory\MemoryStore;
use BgAgents\Repl\ReplPrinter;
use Phalanx\Athena\Swarm\SwarmBus;
use Phalanx\Athena\Swarm\SwarmConfig;
use Phalanx\Athena\Swarm\SwarmEvent;
use Phalanx\Athena\Swarm\SwarmEventKind;
use Phalanx\ExecutionScope;

/**
 * REPL plug for `bookkeeper` / `bk accept N` / `bk dismiss N`.
 *
 * The blackboard is the source of truth: pending issues are computed by
 * pulling all proposal observations and filtering out those followed by
 * an `_applied` or `_dismissed` mate. Survives bg-agents process restart.
 */
final readonly class BookkeeperHandler
{
    public function __construct(
        public ObservationClient $client,
        public SwarmBus $bus,
        public SwarmConfig $swarm,
        public ReplPrinter $printer,
        public MemoryStore $memory,
    ) {}

    public function list(ExecutionScope $scope): void
    {
        $records = self::fetchProposals($scope, $this->client);
        $resolutions = self::fetchResolutions($scope, $this->client);

        $pending = array_filter($records, static function (ObservationRecord $r) use ($resolutions): bool {
            $issueId = self::issueId($r);
            return $issueId !== null && !isset($resolutions[$issueId]);
        });

        if ($pending === []) {
            $this->printer->info('no pending bookkeeper issues');
            return;
        }

        foreach ($pending as $rec) {
            $kind = is_string($rec->data['payload']['issue_kind'] ?? null) ? $rec->data['payload']['issue_kind'] : '?';
            $issueId = self::issueId($rec) ?? '?';
            $suggestion = is_string($rec->data['payload']['suggestion'] ?? null) ? $rec->data['payload']['suggestion'] : '';
            $this->printer->kv("[{$rec->id}] {$kind}", "{$issueId} — {$suggestion}");
        }
    }

    public function accept(ExecutionScope $scope, int $obsId): void
    {
        $this->resolve($scope, $obsId, applied: true);
    }

    public function dismiss(ExecutionScope $scope, int $obsId): void
    {
        $this->resolve($scope, $obsId, applied: false);
    }

    private function resolve(ExecutionScope $scope, int $obsId, bool $applied): void
    {
        $records = self::fetchProposals($scope, $this->client);
        $target = null;
        foreach ($records as $r) {
            if ($r->id === $obsId) {
                $target = $r;
                break;
            }
        }
        if ($target === null) {
            $this->printer->error("no proposal observation with id {$obsId}");
            return;
        }

        $issueId = self::issueId($target);
        if ($issueId === null) {
            $this->printer->error("observation {$obsId} has no issue_id payload");
            return;
        }

        $bgKind = self::resolutionKind($target->bgKind() ?? '', $applied);

        $this->bus->emit(new SwarmEvent(
            from: 'bookkeeper',
            kind: SwarmEventKind::BlackboardPost,
            workspace: $this->swarm->workspace,
            session: $this->swarm->session,
            payload: [
                'bg_kind' => $bgKind,
                'issue_id' => $issueId,
                'resolves_obs' => $obsId,
            ],
        ));

        if ($applied && $target->bgKind() === BgEvent::BOOKKEEPER_PROMOTION_PROPOSED) {
            $this->commitToMemory($target);
        }

        $this->printer->info(($applied ? 'accepted' : 'dismissed') . " issue {$issueId} (obs {$obsId})");
    }

    private function commitToMemory(ObservationRecord $proposal): void
    {
        $payload = is_array($proposal->data['payload'] ?? null) ? $proposal->data['payload'] : [];
        $extra = is_array($payload['extra'] ?? null) ? $payload['extra'] : [];

        $topic = is_string($extra['topic'] ?? null) ? $extra['topic'] : 'untitled';
        $summary = is_string($extra['summary'] ?? null) ? $extra['summary'] : '';
        $tags = is_array($extra['tags'] ?? null)
            ? array_values(array_filter($extra['tags'], is_string(...)))
            : [];
        $sources = is_array($payload['refs'] ?? null)
            ? array_values(array_filter($payload['refs'], is_int(...)))
            : [];

        $this->memory->write(new MemoryRecord(
            topic: $topic,
            summary: $summary,
            tags: $tags,
            sourceObservations: $sources,
        ));
    }

    /** @return list<ObservationRecord> */
    private static function fetchProposals(ExecutionScope $scope, ObservationClient $client): array
    {
        try {
            $result = $scope->await($client->observe(new ObservationQuery(
                kinds: ['custom'],
                limit: 200,
            )));
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter($result['observations'], static function (ObservationRecord $r): bool {
            $bg = $r->bgKind();
            return in_array($bg, [
                BgEvent::BOOKKEEPER_ISSUE,
                BgEvent::BOOKKEEPER_CONSOLIDATION_PROPOSED,
                BgEvent::BOOKKEEPER_PROMOTION_PROPOSED,
            ], true);
        }));
    }

    /** @return array<string, true> */
    private static function fetchResolutions(ExecutionScope $scope, ObservationClient $client): array
    {
        try {
            $result = $scope->await($client->observe(new ObservationQuery(
                kinds: ['custom'],
                limit: 200,
            )));
        } catch (\Throwable) {
            return [];
        }

        $resolutions = [];
        foreach ($result['observations'] as $r) {
            $bg = $r->bgKind();
            if (in_array($bg, [
                BgEvent::BOOKKEEPER_CONSOLIDATION_APPLIED,
                BgEvent::BOOKKEEPER_CONSOLIDATION_DISMISSED,
                BgEvent::BOOKKEEPER_PROMOTION_APPLIED,
                BgEvent::BOOKKEEPER_PROMOTION_DISMISSED,
            ], true)) {
                $issueId = is_string($r->data['payload']['issue_id'] ?? null) ? $r->data['payload']['issue_id'] : null;
                if ($issueId !== null) {
                    $resolutions[$issueId] = true;
                }
            }
        }

        return $resolutions;
    }

    private static function issueId(ObservationRecord $r): ?string
    {
        $id = $r->data['payload']['issue_id'] ?? null;
        return is_string($id) ? $id : null;
    }

    private static function resolutionKind(string $proposalKind, bool $applied): string
    {
        return match (true) {
            $proposalKind === BgEvent::BOOKKEEPER_CONSOLIDATION_PROPOSED && $applied
                => BgEvent::BOOKKEEPER_CONSOLIDATION_APPLIED,
            $proposalKind === BgEvent::BOOKKEEPER_CONSOLIDATION_PROPOSED && !$applied
                => BgEvent::BOOKKEEPER_CONSOLIDATION_DISMISSED,
            $proposalKind === BgEvent::BOOKKEEPER_PROMOTION_PROPOSED && $applied
                => BgEvent::BOOKKEEPER_PROMOTION_APPLIED,
            $proposalKind === BgEvent::BOOKKEEPER_PROMOTION_PROPOSED && !$applied
                => BgEvent::BOOKKEEPER_PROMOTION_DISMISSED,
            default => $applied ? BgEvent::BOOKKEEPER_PROMOTION_APPLIED : BgEvent::BOOKKEEPER_PROMOTION_DISMISSED,
        };
    }
}
