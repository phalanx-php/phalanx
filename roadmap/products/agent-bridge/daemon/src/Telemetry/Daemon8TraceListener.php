<?php

declare(strict_types=1);

namespace AgentBridge\Telemetry;

use React\Http\Browser;

final class Daemon8TraceListener
{
    private readonly Browser $http;

    public function __construct(
        private readonly string $endpoint,
        ?Browser $http = null,
    ) {
        $this->http = $http ?? new Browser();
    }

    public function trace(string $type, string $subject, ?string $detail = null, ?int $durationNs = null): void
    {
        $this->emit('log', 'trace', [
            'type' => $type,
            'subject' => $subject,
            'detail' => $detail,
            'duration_ns' => $durationNs,
            'memory_bytes' => memory_get_usage(true),
        ]);
    }

    public function wire(string $direction, string $type, ?int $tabId, string $summary): void
    {
        $this->emit('custom', 'wire', [
            'direction' => $direction,
            'type' => $type,
            'tabId' => $tabId,
            'summary' => $summary,
        ]);
    }

    public function emit(string $kind, string $channel, array $data): void
    {
        $payload = json_encode([
            'app' => 'agent-bridge',
            'kind' => $kind,
            'channel' => $channel,
            'data' => $data,
            'ts' => hrtime(true),
        ], JSON_THROW_ON_ERROR);

        $this->http->post($this->endpoint, ['Content-Type' => 'application/json'], $payload)
            ->then(null, static fn() => null);
    }
}
