<?php

declare(strict_types=1);

namespace BgAgents\Bookkeeper\Lane;

use BgAgents\Bookkeeper\DetectionPolicy;
use BgAgents\Bookkeeper\Issue;
use BgAgents\Bookkeeper\IssueKind;
use BgAgents\Bookkeeper\IssueStore;
use BgAgents\Config\BgAgentsConfig;
use BgAgents\Daemon8\ObservationClient;
use BgAgents\Daemon8\ObservationQuery;
use BgAgents\Daemon8\ObservationRecord;
use Phalanx\Athena\Agent;
use Phalanx\Athena\AgentLoop;
use Phalanx\Athena\AgentResult;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;

/**
 * Periodic noise consolidator.
 *
 * Every $intervalSec, pulls the past $lookbackSec window from /api/observe,
 * groups by signature, and for any cluster that exceeds the noise threshold
 * asks the cheap consolidator model (default gemini-flash) to produce a
 * structured summary. The proposal is raised as an Issue; user acceptance
 * happens via the REPL bookkeeper handler in a later phase.
 *
 * Cluster signature = origin + kindTag + channel + first non-volatile data
 * key. Coarse on purpose — we want all near-duplicates to land in one
 * cluster so the LLM call is cheap and the summary is coherent.
 */
final class ConsolidationLane implements Executable
{
    public function __invoke(ExecutionScope $scope): mixed
    {
        $client = $scope->service(ObservationClient::class);
        $config = $scope->service(BgAgentsConfig::class);
        $store = $scope->service(IssueStore::class);
        $policy = $scope->service(DetectionPolicy::class);

        // delay-loop instead of Loop::addPeriodicTimer because tickOnce()
        // calls $scope->await(), which requires a fiber context. Timer
        // callbacks fire outside any fiber and would assert-fail.
        while (!$scope->isCancelled) {
            $scope->delay((float) $policy->consolidationIntervalSec);
            if ($scope->isCancelled) {
                break;
            }
            try {
                self::tickOnce($scope, $client, $config, $store, $policy);
            } catch (\Throwable $e) {
                fwrite(STDERR, "[consolidation] tick error: {$e->getMessage()}\n");
            }
        }

        return null;
    }

    private static function tickOnce(
        ExecutionScope $scope,
        ObservationClient $client,
        BgAgentsConfig $config,
        IssueStore $store,
        DetectionPolicy $policy,
    ): void {
        $checkpoint = $scope->await($client->checkpoint());
        $sinceEstimate = (int) max(0, $checkpoint - 5000);

        $result = $scope->await($client->observe(new ObservationQuery(
            kinds: ['custom', 'log'],
            since: $sinceEstimate,
            limit: 500,
        )));

        $clusters = self::clusterBySignature($result['observations']);
        if ($clusters === []) {
            return;
        }

        foreach ($clusters as $signature => $records) {
            if (count($records) < $policy->consolidationNoiseThreshold) {
                continue;
            }
            self::proposeConsolidation($scope, $config, $store, $signature, $records);
        }
    }

    /**
     * @param list<ObservationRecord> $records
     * @return array<string, list<ObservationRecord>>
     */
    private static function clusterBySignature(array $records): array
    {
        $clusters = [];
        foreach ($records as $rec) {
            $sig = self::signature($rec);
            $clusters[$sig] ??= [];
            $clusters[$sig][] = $rec;
        }
        return $clusters;
    }

    private static function signature(ObservationRecord $record): string
    {
        $originName = is_string($record->origin['name'] ?? null) ? $record->origin['name'] : 'unknown';
        $channel = $record->channel() ?? '';
        $bgKind = $record->bgKind() ?? '';
        return "{$originName}|{$record->kindTag}|{$channel}|{$bgKind}";
    }

    /** @param list<ObservationRecord> $records */
    private static function proposeConsolidation(
        ExecutionScope $scope,
        BgAgentsConfig $config,
        IssueStore $store,
        string $signature,
        array $records,
    ): void {
        $clusterId = 'cons-' . substr(sha1($signature . ':' . count($records) . ':' . $records[0]->id), 0, 12);

        if (self::alreadyProposed($store, $clusterId)) {
            return;
        }

        $blob = self::renderClusterBlob($records);
        $prompt = "Consolidate these {$records[0]->kindTag} observations into one summary. "
            . "Strict JSON, keys: summary, distinct_facts, retained_severity.\n\n{$blob}";

        $turn = Agent::quick(self::systemPrompt())
            ->message($prompt);

        $events = AgentLoop::run($turn, $scope, agentName: 'bookkeeper-consolidator');

        try {
            $result = AgentResult::awaitFrom($events, $scope);
        } catch (\Throwable $e) {
            return;
        }

        $summary = self::tryDecodeJson($result->text);

        $refs = array_map(static fn(ObservationRecord $r): int => $r->id, $records);

        $store->raise(new Issue(
            id: $clusterId,
            kind: IssueKind::ConsolidationProposed,
            refs: $refs,
            suggestion: "consolidate {$signature} ({$config->models->bookkeeperConsolidate})",
            payload: [
                'signature' => $signature,
                'cluster_size' => count($records),
                'summary' => $summary['summary'] ?? $result->text,
                'distinct_facts' => $summary['distinct_facts'] ?? [],
                'retained_severity' => $summary['retained_severity'] ?? 'info',
            ],
        ));
    }

    private static function alreadyProposed(IssueStore $store, string $clusterId): bool
    {
        foreach ($store->all() as $existing) {
            if ($existing->id === $clusterId) {
                return true;
            }
        }
        return false;
    }

    /** @param list<ObservationRecord> $records */
    private static function renderClusterBlob(array $records): string
    {
        $lines = [];
        foreach ($records as $r) {
            $excerpt = json_encode($r->data, JSON_UNESCAPED_SLASHES) ?: '(unserializable)';
            $excerpt = mb_strlen($excerpt) > 280 ? mb_substr($excerpt, 0, 280) . '…' : $excerpt;
            $lines[] = "[{$r->id}] {$r->severity} {$excerpt}";
            if (count($lines) >= 80) {
                break;
            }
        }
        return implode("\n", $lines);
    }

    private static function systemPrompt(): string
    {
        return "You are the bg-agents log consolidator. Reduce N near-duplicate "
            . "observations into one summary that preserves every distinct fact. "
            . "Drop repetition. Output strict JSON only: "
            . '{"summary": string, "distinct_facts": [string], "retained_severity": "info|warn|error"}.';
    }

    /** @return array<string, mixed> */
    private static function tryDecodeJson(string $raw): array
    {
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }
        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
        return is_array($decoded) ? $decoded : [];
    }
}
