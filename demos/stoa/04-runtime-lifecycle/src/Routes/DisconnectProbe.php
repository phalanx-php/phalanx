<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Runtime\Routes;

use Acme\StoaDemo\Runtime\Support\RuntimeEvents;
use Phalanx\Stoa\RequestContext;
use Phalanx\Task\Scopeable;

final readonly class DisconnectProbe implements Scopeable
{
    public function __construct(private RuntimeEvents $events)
    {
    }

    /** @return array{status: string} */
    public function __invoke(RequestContext $ctx): array
    {
        $this->events->record($ctx, 'disconnect.started', ['path' => $ctx->path()]);
        $ctx->cancellation()->onCancel(function () use ($ctx): void {
            $this->events->record($ctx, 'disconnect.cancelled', ['path' => $ctx->path()]);
        });

        try {
            for ($tick = 0; $tick < 100; $tick++) {
                $ctx->delay(0.05);
            }

            $this->events->record($ctx, 'disconnect.completed');

            return ['status' => 'completed'];
        } finally {
            $this->events->record($ctx, 'disconnect.finalized', ['path' => $ctx->path()]);
        }
    }
}
