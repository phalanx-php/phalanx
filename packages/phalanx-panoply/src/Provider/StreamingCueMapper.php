<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider;

/**
 * Marker interface for stateful cue translators that consume a stream of
 * wire-protocol events and emit a Series of Cues. Two concrete child
 * interfaces narrow the event type per wire format:
 *
 * - {@see SseStreamingCueMapper} — consumes Sse\Event (Anthropic, OpenAI,
 *   Gemini, HuggingFace).
 * - {@see NdjsonStreamingCueMapper} — consumes array<string, mixed> NDJSON
 *   lines (Ollama).
 *
 * Implementations are stateful (track invocation lifecycle, completion
 * flag, etc.). Instantiate per stream. Call complete() exactly once after
 * the stream terminates to flush trailing cues; complete() must be
 * idempotent so callers may safely call it on cleanup paths.
 */
interface StreamingCueMapper
{
}
