<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider;

use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Sse\Event;

/**
 * Cue mapper that translates SSE events into a stream of Cues.
 * Implemented by Anthropic, OpenAI (Chat + Responses), and Gemini mappers.
 */
interface SseStreamingCueMapper extends StreamingCueMapper
{
    /**
     * @return \Generator<int, Cue>
     */
    public function translate(Event $event): \Generator;

    /**
     * Flush trailing cues after the SSE stream ends. Must be idempotent.
     *
     * @return \Generator<int, Cue>
     */
    public function complete(): \Generator;
}
