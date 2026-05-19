<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider;

use Phalanx\Panoply\Cue;

/**
 * Cue mapper that translates NDJSON lines into a stream of Cues.
 * Implemented by Ollama mapper.
 */
interface NdjsonStreamingCueMapper extends StreamingCueMapper
{
    /**
     * @param array<string, mixed> $line
     * @return \Generator<int, Cue>
     */
    public function translate(array $line): \Generator;

    /**
     * Flush trailing cues after the NDJSON stream ends. Must be idempotent.
     *
     * @return \Generator<int, Cue>
     */
    public function complete(): \Generator;
}
