<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider\Anthropic;

/**
 * Stateful SSE parser for Anthropic's streaming Messages API.
 *
 * SSE events are delimited by double newlines (`\n\n`). Each event block
 * contains one `event:` line and one `data:` line. Lines starting with
 * `:` are comments and are silently ignored. Events whose data is not
 * valid JSON or whose event type is empty are silently dropped so the
 * stream survives unknown future event shapes.
 *
 * Usage pattern:
 * ```php
 * $parser = new SseParser();
 * foreach ($transport->stream($request, $runtime) as $chunk) {
 *     foreach ($parser->feed($chunk) as $event) { ... }
 * }
 * foreach ($parser->flush() as $event) { ... }
 * ```
 *
 * Final — sealed; the buffer accumulation invariant is a correctness
 * property that subclasses cannot safely alter.
 */
final class SseParser
{
    private string $buffer = '';

    /**
     * Accept the next byte chunk and yield any complete SSE events found
     * in the accumulated buffer.
     *
     * Line endings are normalized to LF at the start of each feed call so
     * the buffer invariant stays LF-only. The SSE spec (WHATWG) accepts
     * \r\n, \n, or \r as line endings and \r\n\r\n, \n\n, or \r\r as event
     * delimiters; normalizing to \n\n covers all three cases uniformly.
     *
     * @return \Generator<int, SseEvent>
     */
    public function feed(string $chunk): \Generator
    {
        if ($chunk === '') {
            return;
        }

        // Normalize all line-ending variants to LF so the buffer invariant
        // holds regardless of which delimiter the server sends.
        $chunk = str_replace(["\r\n", "\r"], "\n", $chunk);

        $this->buffer .= $chunk;

        while (($pos = strpos($this->buffer, "\n\n")) !== false) {
            $eventText     = substr($this->buffer, 0, $pos);
            $this->buffer  = substr($this->buffer, $pos + 2);

            $event = self::parseEvent($eventText);
            if ($event !== null) {
                yield $event;
            }
        }
    }

    /**
     * Flush any trailing data in the buffer as a final event. Call after
     * the transport stream is exhausted.
     *
     * @return \Generator<int, SseEvent>
     */
    public function flush(): \Generator
    {
        if ($this->buffer === '') {
            return;
        }

        $event        = self::parseEvent($this->buffer);
        $this->buffer = '';

        if ($event !== null) {
            yield $event;
        }
    }

    private static function parseEvent(string $eventText): ?SseEvent
    {
        $lines     = explode("\n", trim($eventText));
        $eventType = '';
        $dataLines = [];

        foreach ($lines as $line) {
            // Per SSE spec the colon-space is optional; `event:foo` is valid.
            if (preg_match('/^event:\s?(.*)$/', $line, $m) === 1) {
                $eventType = $m[1];
            } elseif (preg_match('/^data:\s?(.*)$/', $line, $m) === 1) {
                $dataLines[] = $m[1];
            }
            // Lines starting with ':' are SSE comments — silently ignored.
            // Unknown field names are also ignored for forward compatibility.
        }

        if ($eventType === '' || $dataLines === []) {
            return null;
        }

        $dataJson = implode("\n", $dataLines);

        try {
            $data = json_decode($dataJson, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        return new SseEvent($eventType, $data);
    }
}
