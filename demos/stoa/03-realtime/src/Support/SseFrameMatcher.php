<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Realtime\Support;

final readonly class SseFrameMatcher
{
    /**
     * @param list<array{event?: string, id?: string, data: string}> $frames
     */
    public function __invoke(array $frames, string $event): bool
    {
        foreach ($frames as $frame) {
            if (($frame['event'] ?? null) !== $event) {
                return false;
            }
        }

        return true;
    }
}
