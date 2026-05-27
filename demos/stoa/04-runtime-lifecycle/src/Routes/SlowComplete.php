<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Runtime\Routes;

use Acme\StoaDemo\Runtime\Support\RuntimeEvents;
use Phalanx\Stoa\RequestContext;
use Phalanx\Task\Scopeable;

final readonly class SlowComplete implements Scopeable
{
    public function __construct(private RuntimeEvents $events)
    {
    }

    /** @return array{status: string} */
    public function __invoke(RequestContext $ctx): array
    {
        $this->events->record($ctx, 'slow.started', ['path' => $ctx->path()]);
        $ctx->delay(0.15);
        $this->events->record($ctx, 'slow.completed', ['path' => $ctx->path()]);

        return ['status' => 'completed'];
    }
}
