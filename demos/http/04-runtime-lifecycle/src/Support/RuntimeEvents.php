<?php

declare(strict_types=1);

namespace Acme\HttpDemo\Runtime\Support;

use Phalanx\Runtime\Memory\RuntimeLifecycleEvent;
use Phalanx\Http\RequestContext;

final readonly class RuntimeEvents
{
    /** @param array<string, string|int|float|bool|null> $context */
    public function record(RequestContext $ctx, string $event, array $context = []): void
    {
        $ctx->runtime->memory->resources->recordEvent(
            $ctx->requestId,
            $event,
            (string) ($context['path'] ?? $ctx->path()),
            (string) ($context['detail'] ?? ''),
        );
    }

    /** @return list<array{event: string, context: array<string, mixed>, at: float}> */
    public function all(RequestContext $ctx): array
    {
        return array_values(array_map(
            self::format(...),
            $ctx->runtime->memory->events->recent(),
        ));
    }

    /** @return array{event: string, context: array<string, mixed>, at: float} */
    private static function format(RuntimeLifecycleEvent $event): array
    {
        return [
            'event' => $event->type,
            'context' => [
                'path' => $event->valueA,
                'run' => $event->runId,
                'state' => $event->state,
                'scope' => $event->scopeId,
                'detail' => $event->valueB,
                'value_a' => $event->valueA,
                'value_b' => $event->valueB,
                'resource' => $event->resourceId,
                'sequence' => $event->sequence,
            ],
            'at' => $event->occurredAt,
        ];
    }
}
