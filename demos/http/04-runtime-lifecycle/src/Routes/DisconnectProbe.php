<?php

declare(strict_types=1);

namespace Acme\HttpDemo\Runtime\Routes;

use Acme\HttpDemo\Runtime\Support\RuntimeEvents;
use Phalanx\Mark\Mark;
use Phalanx\Http\RequestContext;
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
        $events = $this->events;

        $ctx->cancellation()->onCancel(static function () use ($ctx, $events): void {
            $events->record($ctx, 'disconnect.cancelled', ['path' => $ctx->path()]);
        });

        try {
            for ($tick = 0; $tick < 100; $tick++) {
                $ctx->delay(Mark::ms(50));
            }

            $this->events->record($ctx, 'disconnect.completed');

            return ['status' => 'completed'];
        } finally {
            $this->events->record($ctx, 'disconnect.finalized', ['path' => $ctx->path()]);
        }
    }
}
