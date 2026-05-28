<?php

declare(strict_types=1);

namespace BgAgents\Team;

use BgAgents\Bookkeeper\BookkeeperRunner;
use BgAgents\Daemon8\BgEvent;
use Phalanx\Athena\Swarm\SwarmBus;
use Phalanx\Athena\Swarm\SwarmConfig;
use Phalanx\Athena\Swarm\SwarmEvent;
use Phalanx\Athena\Swarm\SwarmEventKind;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Task;

/**
 * Long-lived team supervisor.
 *
 * Phase 3 wires the heartbeat lane only. Phase 4 layers the bookkeeper
 * (HygieneLane + ConsolidationLane + PromotionLane) onto the same
 * concurrent fan-out. The pattern is: $scope->concurrent([... lanes ...])
 * which blocks until all complete OR the parent scope disposes.
 *
 * Lifecycle: emits team.started before fan-out, team.stopped in onDispose.
 */
final class TeamRunner implements Executable
{
    public function __invoke(ExecutionScope $scope): mixed
    {
        $bus = $scope->service(SwarmBus::class);
        $swarm = $scope->service(SwarmConfig::class);

        self::emitLifecycle($bus, $swarm, BgEvent::TEAM_STARTED);

        $scope->onDispose(static function () use ($bus, $swarm): void {
            self::emitLifecycle($bus, $swarm, BgEvent::TEAM_STOPPED);
        });

        return $scope->concurrent([
            Task::of(static fn(ExecutionScope $s): mixed => $s->execute(new TeamHeartbeat())),
            Task::of(static fn(ExecutionScope $s): mixed => $s->execute(new BookkeeperRunner())),
        ]);
    }

    private static function emitLifecycle(SwarmBus $bus, SwarmConfig $swarm, string $bgKind): void
    {
        $bus->emit(new SwarmEvent(
            from: 'bg-agents-team',
            kind: SwarmEventKind::BlackboardPost,
            workspace: $swarm->workspace,
            session: $swarm->session,
            payload: ['bg_kind' => $bgKind],
        ));
    }
}
