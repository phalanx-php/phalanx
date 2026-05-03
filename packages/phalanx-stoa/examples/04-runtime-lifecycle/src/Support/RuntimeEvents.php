<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Runtime\Support;

use Phalanx\Runtime\Memory\RuntimeLifecycleEvent;
use Phalanx\Stoa\RequestScope;

final readonly class RuntimeEvents
{
    /** @param array<string, string|int|float|bool|null> $context */
    public function record(RequestScope $scope, string $event, array $context = []): void
    {
        $scope->runtime->memory->resources->recordEvent(
            $scope->resourceId,
            $event,
            (string) ($context['path'] ?? $scope->path()),
            (string) ($context['detail'] ?? ''),
        );
    }

    /** @return list<array{event: string, context: array<string, mixed>, at: float}> */
    public function all(RequestScope $scope): array
    {
        return array_map(
            self::format(...),
            $scope->runtime->memory->events->recent(),
        );
    }

    private static function format(RuntimeLifecycleEvent $event): array
    {
        return [
            'event' => $event->type,
            'context' => [
                'run' => $event->runId,
                'state' => $event->state,
                'scope' => $event->scopeId,
                'value_a' => $event->valueA,
                'value_b' => $event->valueB,
                'resource' => $event->resourceId,
                'sequence' => $event->sequence,
            ],
            'at' => $event->occurredAt,
        ];
    }
}
