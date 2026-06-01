<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Projection;

use Phalanx\Theatron\Collab\Events\CollabEvent;
use Phalanx\Theatron\Collab\State\CollabStore;

final class CollabReplay
{
    public function __construct(
        private CollabProjector $projector = new CollabProjector(),
    ) {
    }

    /**
     * @param iterable<CollabEvent> $events
     */
    public function __invoke(iterable $events, ?CollabStore $store = null): CollabStore
    {
        return $this->replay($events, $store);
    }

    /**
     * @param iterable<CollabEvent> $events
     */
    public function replay(iterable $events, ?CollabStore $store = null): CollabStore
    {
        $store ??= new CollabStore();

        foreach ($events as $event) {
            $this->projector->project($event, $store);
        }

        return $store;
    }
}
