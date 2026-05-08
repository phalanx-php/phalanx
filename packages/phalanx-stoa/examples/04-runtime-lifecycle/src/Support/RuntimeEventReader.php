<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Runtime\Support;

final readonly class RuntimeEventReader
{
    /**
     * @return list<array{event: string, context: array<string, mixed>, at: float}>
     */
    public function __invoke(string $host, int $port): array
    {
        $httpGet = new SimpleHttpGet();
        $response = $httpGet($host, $port, '/runtime/events');

        if ($response['status'] !== 200) {
            return [];
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded) || !isset($decoded['events']) || !is_array($decoded['events'])) {
            return [];
        }

        return $decoded['events'];
    }
}
