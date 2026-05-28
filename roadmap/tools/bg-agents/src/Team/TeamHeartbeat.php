<?php

declare(strict_types=1);

namespace BgAgents\Team;

use BgAgents\Daemon8\BgEvent;
use Phalanx\Athena\Swarm\SwarmBus;
use Phalanx\Athena\Swarm\SwarmConfig;
use Phalanx\Athena\Swarm\SwarmEvent;
use Phalanx\Athena\Swarm\SwarmEventKind;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use React\EventLoop\Loop;
use React\Promise\Deferred;

/**
 * Periodic heartbeat: emits a BlackboardPost with bg_kind=team.heartbeat
 * every $intervalSec seconds. Other surfaces query the latest heartbeat to
 * decide if a team is alive (`bg-agents team status`).
 *
 * The timer reference lives in a closed-over local; onDispose cancels it
 * AND clears the local so the closure can be GC'd cleanly on shutdown.
 */
final readonly class TeamHeartbeat implements Executable
{
    public function __construct(
        public int $intervalSec = 30,
    ) {}

    public function __invoke(ExecutionScope $scope): mixed
    {
        $bus = $scope->service(SwarmBus::class);
        $swarm = $scope->service(SwarmConfig::class);
        $started = hrtime(true);
        $count = 0;

        $emit = static function () use ($bus, $swarm, $started, &$count): void {
            $count++;
            $uptimeSec = (hrtime(true) - $started) / 1e9;
            $bus->emit(new SwarmEvent(
                from: 'bg-agents-team',
                kind: SwarmEventKind::BlackboardPost,
                workspace: $swarm->workspace,
                session: $swarm->session,
                payload: [
                    'bg_kind' => BgEvent::TEAM_HEARTBEAT,
                    'sequence' => $count,
                    'uptime_sec' => $uptimeSec,
                ],
            ));
        };

        $emit();

        $timer = Loop::addPeriodicTimer($this->intervalSec, $emit);

        $suspend = new Deferred();
        $resolved = false;
        $scope->onDispose(static function () use (&$timer, &$suspend, &$resolved): void {
            if ($timer !== null) {
                Loop::cancelTimer($timer);
                $timer = null;
            }
            if (!$resolved) {
                $resolved = true;
                Loop::futureTick(static fn() => $suspend->resolve(null));
            }
        });

        $scope->await($suspend->promise());

        return null;
    }
}
