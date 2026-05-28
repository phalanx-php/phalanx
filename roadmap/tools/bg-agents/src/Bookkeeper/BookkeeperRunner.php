<?php

declare(strict_types=1);

namespace BgAgents\Bookkeeper;

use BgAgents\Bookkeeper\Lane\ConsolidationLane;
use BgAgents\Bookkeeper\Lane\HygieneLane;
use BgAgents\Daemon8\BgEvent;
use Phalanx\Athena\Swarm\SwarmBus;
use Phalanx\Athena\Swarm\SwarmConfig;
use Phalanx\Athena\Swarm\SwarmEvent;
use Phalanx\Athena\Swarm\SwarmEventKind;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Task;

/**
 * Long-lived bookkeeper. Runs three logical lanes; v1 wires Hygiene + Consolidation.
 *
 * Promotion lane is intentionally deferred — it depends on the RAG memory
 * store landing in Phase 5 to write back to. The structure is in place
 * (DetectionPolicy::promotionInterval, IssueKind::PromotionProposed) so
 * Phase 5 only adds the lane file and the accept-handler write path.
 */
final class BookkeeperRunner implements Executable
{
    public function __invoke(ExecutionScope $scope): mixed
    {
        $bus = $scope->service(SwarmBus::class);
        $swarm = $scope->service(SwarmConfig::class);

        self::emitLifecycle($bus, $swarm, BgEvent::BOOKKEEPER_ONLINE);

        $scope->onDispose(static function () use ($bus, $swarm): void {
            self::emitLifecycle($bus, $swarm, BgEvent::BOOKKEEPER_OFFLINE);
        });

        return $scope->concurrent([
            Task::of(static fn(ExecutionScope $s): mixed => $s->execute(new HygieneLane())),
            Task::of(static fn(ExecutionScope $s): mixed => $s->execute(new ConsolidationLane())),
        ]);
    }

    private static function emitLifecycle(SwarmBus $bus, SwarmConfig $swarm, string $bgKind): void
    {
        $bus->emit(new SwarmEvent(
            from: 'bookkeeper',
            kind: SwarmEventKind::BlackboardPost,
            workspace: $swarm->workspace,
            session: $swarm->session,
            payload: ['bg_kind' => $bgKind],
        ));
    }
}
