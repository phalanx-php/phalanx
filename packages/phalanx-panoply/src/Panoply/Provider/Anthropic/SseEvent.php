<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider\Anthropic;

/**
 * Internal value object carrying one parsed SSE event from Anthropic's
 * streaming Messages API. Used only between {@see SseParser} and
 * {@see CueMapper}; not part of the public panoply surface.
 *
 * Final — sealed internal carrier; extension is neither needed nor safe.
 */
final class SseEvent
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
