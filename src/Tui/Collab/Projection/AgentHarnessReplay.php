<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\Projection;

use Phalanx\Tui\Collab\Events\AgentHarnessEvent;
use Phalanx\Tui\Collab\Plans\WorkPlan;
use Phalanx\Tui\Collab\State\AgentHarnessStore;
use Phalanx\Tui\Collab\State\WorkPlanSlice;

final class AgentHarnessReplay
{
    public function __construct(
        private AgentHarnessProjector $projector = new AgentHarnessProjector(),
    ) {
    }

    /**
     * @param iterable<AgentHarnessEvent> $events
     */
    public function __invoke(iterable $events, ?AgentHarnessStore $store = null): AgentHarnessStore
    {
        return $this->replay($events, $store);
    }

    /**
     * @param iterable<AgentHarnessEvent> $events
     */
    public function replay(iterable $events, ?AgentHarnessStore $store = null): AgentHarnessStore
    {
        $store ??= self::store();

        foreach ($events as $event) {
            $this->projector->project($event, $store);
        }

        return $store;
    }

    private static function store(): AgentHarnessStore
    {
        $store = new AgentHarnessStore();
        $store->workPlan = new WorkPlanSlice(WorkPlan::empty('replay'));

        return $store;
    }
}
