<?php

declare(strict_types=1);

namespace BgAgents\Bookkeeper\Lane;

use BgAgents\Bookkeeper\DetectionPolicy;
use BgAgents\Bookkeeper\Issue;
use BgAgents\Bookkeeper\IssueStore;
use Phalanx\Athena\Swarm\SwarmBus;
use Phalanx\Athena\Swarm\SwarmEvent;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use React\EventLoop\Loop;

/**
 * Live duplicate detector over the swarm event stream.
 *
 * Fingerprint = sha1(from || bg_kind || canonical_payload). For each new
 * event we check the sliding window (default 60s) and raise a Duplicate
 * issue if we've seen the same fingerprint inside it.
 *
 * Conflict / Stale / Contradiction detection are intentional gaps for v1 —
 * the structure is in place (DetectionPolicy, IssueKind enum) but the
 * pattern matchers add complexity without proving the architecture. Build
 * them once we have a real noisy workload to learn from.
 */
final class HygieneLane implements Executable
{
    public function __invoke(ExecutionScope $scope): mixed
    {
        $bus = $scope->service(SwarmBus::class);
        $store = $scope->service(IssueStore::class);
        $policy = $scope->service(DetectionPolicy::class);

        /** @var array<string, array{ts: float, ref: int}> $window */
        $window = [];

        $prune = static function () use (&$window, $policy): void {
            $cutoff = microtime(true) - $policy->duplicateWindowSec;
            foreach ($window as $fp => $entry) {
                if ($entry['ts'] < $cutoff) {
                    unset($window[$fp]);
                }
            }
        };

        $pruneTimer = Loop::addPeriodicTimer($policy->duplicateWindowSec, $prune);

        $scope->onDispose(static function () use (&$pruneTimer): void {
            if ($pruneTimer !== null) {
                Loop::cancelTimer($pruneTimer);
                $pruneTimer = null;
            }
        });

        $events = $bus->subscribe([]);

        foreach ($events($scope) as $event) {
            if (!$event instanceof SwarmEvent) {
                continue;
            }

            if ($event->from === 'bookkeeper') {
                continue;
            }

            $fp = self::fingerprint($event);
            $now = microtime(true);

            if (isset($window[$fp])) {
                $previous = $window[$fp];
                $age = $now - $previous['ts'];
                if ($age <= $policy->duplicateWindowSec) {
                    $currentId = self::idForEvent($event);
                    $store->raise(Issue::duplicate($fp, $previous['ref'], $currentId));
                }
            }

            $window[$fp] = ['ts' => $now, 'ref' => self::idForEvent($event)];
        }

        return null;
    }

    private static function fingerprint(SwarmEvent $event): string
    {
        $bgKind = $event->payload['bg_kind'] ?? $event->kind->value;
        $payload = $event->payload;

        unset($payload['latency_ms'], $payload['tokens'], $payload['steps'], $payload['sequence'], $payload['uptime_sec']);
        ksort($payload);

        return substr(sha1($event->from . '|' . $bgKind . '|' . json_encode($payload, JSON_UNESCAPED_SLASHES)), 0, 16);
    }

    private static function idForEvent(SwarmEvent $event): int
    {
        if ($event->eventId !== null && preg_match('/_(\d+)$/', $event->eventId, $m) === 1) {
            return (int) $m[1];
        }
        return abs(crc32($event->eventId ?? uniqid()));
    }
}
