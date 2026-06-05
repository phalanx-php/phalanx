<?php

declare(strict_types=1);

namespace Acme\HttpDemo\Runtime\Routes;

use Acme\HttpDemo\Runtime\Support\RuntimeEvents;
use Phalanx\Mark\Mark;
use Phalanx\Http\RequestContext;
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
        $ctx->delay(Mark::ms(150));
        $this->events->record($ctx, 'slow.completed', ['path' => $ctx->path()]);

        return ['status' => 'completed'];
    }
}
