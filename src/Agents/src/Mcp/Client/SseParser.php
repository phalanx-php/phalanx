<?php

declare(strict_types=1);

namespace Phalanx\Agents\Mcp\Client;

use Generator;

/**
 * Parses SSE event-stream format from a raw line generator (including empty
 * lines — do NOT use Stream::lines() which strips them).
 *
 * Yields arrays with keys: event (string), data (string), id (?string).
 * Blank line flushes the accumulated fields as one event; incomplete
 * events at stream end are discarded per the SSE spec.
 *
 * @phpstan-type SseEvent array{event: string, data: string, id: ?string}
 */
final class SseParser
{
    /**
     * @param Generator<int, string> $lines
     * @return Generator<int, array{event: string, data: string, id: ?string}>
     */
    public function parse(Generator $lines): Generator
    {
        $event = 'message';
        $id = null;
        /** @var list<string> $dataParts */
        $dataParts = [];

        foreach ($lines as $line) {
            if ($line === '') {
                if ($dataParts !== []) {
                    yield [
                        'event' => $event,
                        'data' => implode("\n", $dataParts),
                        'id' => $id,
                    ];
                }

                $event = 'message';
                $id = null;
                $dataParts = [];
                continue;
            }

            if (str_starts_with($line, ':')) {
                continue;
            }

            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }

            $name = substr($line, 0, $colon);
            $value = ltrim(substr($line, $colon + 1), ' ');

            match ($name) {
                'event' => $event = $value,
                'data' => $dataParts[] = $value,
                'id' => $id = $value,
                default => null,
            };
        }
    }
}
