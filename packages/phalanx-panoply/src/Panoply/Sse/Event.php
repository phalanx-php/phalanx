<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Sse;

/**
 * Internal value object carrying one parsed SSE event. Used between
 * {@see Parser} and cue mappers; not part of the public panoply surface.
 *
 * Final — sealed internal carrier; extension is neither needed nor safe.
 */
final class Event
{
    /**
     * @param array<string, mixed> $data decoded JSON payload from the data field
     */
    public function __construct(
        private(set) string $type,
        private(set) array $data,
    ) {
    }
}
