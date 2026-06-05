<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\Projection;

use Phalanx\Tui\Collab\Events\Event;
use Phalanx\Tui\Collab\Plans\WorkPlan;
use Phalanx\Tui\Collab\State\Store;
use Phalanx\Tui\Collab\State\WorkPlanSlice;

final class Replay
{
    public function __construct(
        private Projector $projector = new Projector(),
    ) {
    }

    /**
     * @param iterable<Event> $events
     */
    public function __invoke(iterable $events, ?Store $store = null): Store
    {
        return $this->replay($events, $store);
    }

    /**
     * @param iterable<Event> $events
     */
    public function replay(iterable $events, ?Store $store = null): Store
    {
        $store ??= self::store();

        foreach ($events as $event) {
            $this->projector->project($event, $store);
        }

        return $store;
    }

    private static function store(): Store
    {
        $store = new Store();
        $store->workPlan = new WorkPlanSlice(WorkPlan::empty('replay'));

        return $store;
    }
}
