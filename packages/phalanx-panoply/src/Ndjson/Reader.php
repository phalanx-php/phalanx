<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Ndjson;

/**
 * Stateful chunk-based NDJSON reader. Accumulates byte chunks from a
 * streaming transport and yields one decoded JSON object per newline-
 * terminated line.
 *
 * CRLF and bare CR line endings are normalized to LF before buffering,
 * matching the tolerance of {@see \Phalanx\Panoply\Sse\Parser}. Lines
 * that are not valid JSON are silently dropped so the stream survives
 * malformed or unexpected lines.
 *
 * Usage pattern:
 * ```php
 * $reader = new Reader();
 * foreach ($transport->stream($request, $runtime) as $chunk) {
 *     foreach ($reader->feed($chunk) as $line) { ... }
 * }
 * foreach ($reader->flush() as $line) { ... }
 * ```
 *
 * Final — sealed; the buffer accumulation invariant is a correctness
 * property that subclasses cannot safely alter.
 */
final class Reader
{
    private string $buffer = '';

    /**
     * Accept the next byte chunk and yield any complete JSON objects found
     * in the accumulated buffer (one per newline-terminated line).
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function feed(string $chunk): \Generator
    {
        if ($chunk === '') {
            return;
        }

        // Normalize CRLF and bare CR to LF so the buffer invariant holds
        // regardless of which line ending the server sends.
        $chunk = str_replace(["\r\n", "\r"], "\n", $chunk);

        $this->buffer .= $chunk;

        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 1);

            $parsed = self::parseLine($line);
            if ($parsed !== null) {
                yield $parsed;
            }
        }
    }

    /**
     * Flush any trailing data in the buffer as a final JSON object.
     * Call after the transport stream is exhausted.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function flush(): \Generator
    {
        if ($this->buffer === '') {
            return;
        }

        $parsed = self::parseLine($this->buffer);
        $this->buffer = '';

        if ($parsed !== null) {
            yield $parsed;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function parseLine(string $line): ?array
    {
        $trimmed = trim($line);

        if ($trimmed === '') {
            return null;
        }

        try {
            $data = json_decode($trimmed, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Silently drop malformed lines — consistent with Sse\Parser tolerance.
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        return $data;
    }
}
