<?php

declare(strict_types=1);

namespace Phalanx\Eidolon\Signal;

final class Envelope
{
    /**
     * @param mixed $data
     * @return array{data: mixed, meta: array{signals: list<array<string, mixed>>, timestamp: int, trace_id: string|null}}
     */
    public static function wrap(mixed $data, SignalCollector $collector, ?string $traceId = null): array
    {
        return [
            '__envelope' => true,
            'data'       => $data,
            'meta'       => [
                'signals'   => $collector->drain(),
                'timestamp' => (int) (microtime(true) * 1000),
                'trace_id'  => $traceId,
            ],
        ];
    }

    /**
     * Returns true when $data carries the envelope shape (has 'data' key and 'meta.signals').
     * Used by EnvelopeMiddleware to skip double-wrapping from nested handlers.
     */
    public static function isEnvelope(mixed $data): bool
    {
        if (!is_array($data)) {
            return false;
        }

        return ($data['__envelope'] ?? false) === true
            && array_key_exists('data', $data)
            && isset($data['meta'])
            && is_array($data['meta'])
            && array_key_exists('signals', $data['meta']);
    }
}
