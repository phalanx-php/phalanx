<?php

declare(strict_types=1);

namespace Phalanx\Http\Sse;

final class SseEncoder
{
    public static function encode(
        string $data,
        ?string $event = null,
        ?string $id = null,
        ?int $retry = null,
    ): string {
        $output = '';

        if ($id !== null) {
            $output .= "id: {$id}\n";
        }

        if ($event !== null) {
            $output .= "event: {$event}\n";
        }

        if ($retry !== null) {
            $output .= "retry: {$retry}\n";
        }

        foreach (explode("\n", $data) as $line) {
            $output .= "data: {$line}\n";
        }

        return $output . "\n";
    }
}
