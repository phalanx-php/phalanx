<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Runtime\Routes;

use Acme\StoaDemo\Runtime\Support\RuntimeEvents;
use Phalanx\Stoa\RequestContext;
use Phalanx\Task\Scopeable;

final readonly class Events implements Scopeable
{
    public function __construct(private RuntimeEvents $events)
    {
    }

    /** @return array{events: list<array{event: string, context: array<string, mixed>, at: float}>} */
    public function __invoke(RequestContext $ctx): array
    {
        return ['events' => $this->events->all($ctx)];
    }
}
