<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Sse;

/**
 * Pure SSE frame encoder per the WHATWG Server-Sent Events spec.
 *
 * Multi-line `data` values are split into one `data:` field per line so the
 * stream parser receives exactly the original payload after concatenation.
 * Each event is terminated with a single empty line.
 */
final class SseEncoder
{
    public static function event(
        string $data,
        ?string $event = null,
        ?string $id = null,
        ?int $retryMs = null,
    ): string {
        $frame = '';

        if ($id !== null && $id !== '') {
            $frame .= 'id: ' . self::stripNewlines($id) . "\n";
        }

        if ($event !== null && $event !== '') {
            $frame .= 'event: ' . self::stripNewlines($event) . "\n";
        }

        if ($retryMs !== null && $retryMs > 0) {
            $frame .= 'retry: ' . $retryMs . "\n";
        }

        $lines = preg_split('/\r\n|\r|\n/', $data);

        foreach ($lines === false ? [$data] : $lines as $line) {
            $frame .= 'data: ' . $line . "\n";
        }

        return $frame . "\n";
    }

    public static function comment(string $comment): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $comment);

        $frame = '';
        foreach ($lines === false ? [$comment] : $lines as $line) {
            $frame .= ': ' . $line . "\n";
        }

        return $frame . "\n";
    }

    private static function stripNewlines(string $value): string
    {
        return strtr($value, ["\r" => '', "\n" => '']);
    }
}
