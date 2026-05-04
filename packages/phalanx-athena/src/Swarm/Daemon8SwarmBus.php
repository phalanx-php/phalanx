<?php

declare(strict_types=1);

namespace Phalanx\Athena\Swarm;

use Phalanx\Athena\Stream\HttpSseSource;
use Phalanx\Scope\Stream\StreamContext;
use Phalanx\Scope\Suspendable;
use Phalanx\Styx\Channel;
use Phalanx\Styx\Emitter;
use Phalanx\Support\ErrorHandler;
use Phalanx\System\HttpClient;
use Phalanx\System\HttpException;
use Phalanx\System\HttpRequest;
use RuntimeException;

/**
 * Swarm bus implementation using Daemon8 as the central blackboard.
 *
 * `emit` POSTs to `/ingest`; `subscribe` opens an SSE stream against
 * `/api/stream`. Both flow through the Aegis-managed {@see HttpClient}
 * so cancellation, wait reasons, and disposal stay visible.
 */
final class Daemon8SwarmBus implements SwarmBus
{
    private readonly HttpClient $client;

    public function __construct(
        private readonly SwarmConfig $config,
        ?HttpClient $client = null,
    ) {
        $this->client = $client ?? self::buildClient($config);
    }

    public function emit(Suspendable $scope, SwarmEvent $event): void
    {
        $payload = [
            'kind' => 'custom',
            'channel' => 'swarm_message',
            'severity' => 'info',
            'app' => $this->config->app,
            'data' => $event->toArray(),
        ];

        try {
            $this->client->send($scope, new HttpRequest(
                method: 'POST',
                path: '/ingest',
                body: json_encode($payload, JSON_THROW_ON_ERROR),
                headers: ['content-type' => 'application/json'],
            ));
        } catch (HttpException $e) {
            ErrorHandler::report('Daemon8 swarm ingest failed: ' . $e->getMessage());
        }
    }

    public function subscribe(array $filters = []): Emitter
    {
        $client = $this->client;
        $config = $this->config;

        $query = http_build_query([
            'kinds' => 'custom',
            'origins' => 'app:' . $config->app,
        ]);
        $path = "/api/stream?{$query}";

        return Emitter::produce(static function (Channel $channel, StreamContext $ctx) use ($client, $path, $filters): void {
            $stream = $client->stream($ctx, new HttpRequest(
                method: 'GET',
                path: $path,
                headers: ['accept' => 'text/event-stream'],
            ));
            $ctx->onDispose(static fn() => $stream->close());

            if ($stream->status >= 400) {
                throw new RuntimeException("Daemon8 stream error {$stream->status}");
            }

            $source = new HttpSseSource($stream);

            foreach ($source->events($ctx) as $sseEvent) {
                $ctx->throwIfCancelled();
                $data = $sseEvent['data'];
                if ($data === '' || $data === '[DONE]') {
                    continue;
                }

                $obs = json_decode($data, true);
                if (!is_array($obs)) {
                    continue;
                }

                $event = self::eventFromObservation($obs, $filters);
                if ($event !== null) {
                    $channel->emit($event);
                }
            }

            $channel->complete();
        });
    }

    /**
     * @param array<string, mixed> $obs
     * @param array<string, mixed> $filters
     *
     * @internal
     */
    public static function eventFromObservation(array $obs, array $filters = []): ?SwarmEvent
    {
        if (!self::isSwarmObservation($obs)) {
            return null;
        }

        $swarmData = $obs['data'] ?? [];
        if (!is_array($swarmData) || ($swarmData['schema'] ?? null) !== 'phalanx.swarm.v1') {
            return null;
        }

        if (!self::matches($swarmData, $filters)) {
            return null;
        }

        return new SwarmEvent(
            from: (string) $swarmData['from'],
            kind: SwarmEventKind::from((string) $swarmData['kind']),
            workspace: (string) $swarmData['workspace'],
            session: (string) $swarmData['session'],
            payload: is_array($swarmData['payload'] ?? null) ? $swarmData['payload'] : [],
            addressedTo: self::addressedTo($swarmData['addressed_to'] ?? null),
            traceId: is_string($swarmData['trace_id'] ?? null) ? $swarmData['trace_id'] : null,
            causationId: is_string($swarmData['causation_id'] ?? null) ? $swarmData['causation_id'] : null,
            eventId: is_string($swarmData['event_id'] ?? null) ? $swarmData['event_id'] : null,
        );
    }

    private static function buildClient(SwarmConfig $config): HttpClient
    {
        $parts = parse_url($config->daemon8Url);
        $host = (string) ($parts['host'] ?? 'localhost');
        $tls = ($parts['scheme'] ?? 'http') === 'https';
        $port = (int) ($parts['port'] ?? ($tls ? 443 : 8888));
        return new HttpClient($host, $port, tls: $tls);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $filters
     */
    private static function matches(array $data, array $filters): bool
    {
        foreach ($filters as $key => $target) {
            if ($target === null) {
                continue;
            }

            $actual = match ($key) {
                'kinds' => $data['kind'] ?? null,
                default => $data[$key] ?? null,
            };

            if ($key === 'addressed_to') {
                if ($target === 'ALL') {
                    continue;
                }
                if ($actual === 'ALL') {
                    continue;
                }

                $targets = array_map(self::normalizeFilterValue(...), (array) $target);
                $actuals = array_map(self::normalizeFilterValue(...), (array) $actual);
                if (empty(array_intersect($targets, $actuals))) {
                    return false;
                }
                continue;
            }

            if (is_array($target)) {
                $targets = array_map(self::normalizeFilterValue(...), $target);
                if (!in_array(self::normalizeFilterValue($actual), $targets, true)) {
                    return false;
                }
            } elseif ($actual !== $target) {
                if (self::normalizeFilterValue($actual) !== self::normalizeFilterValue($target)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string, mixed> $obs */
    private static function isSwarmObservation(array $obs): bool
    {
        $kind = $obs['kind'] ?? null;

        return is_array($kind)
            && ($kind['type'] ?? null) === 'custom'
            && ($kind['channel'] ?? null) === 'swarm_message';
    }

    private static function normalizeFilterValue(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }

    /** @return string|list<string>|null */
    private static function addressedTo(mixed $value): string|array|null
    {
        if (is_string($value)) {
            return $value;
        }

        if (!is_array($value)) {
            return null;
        }

        /** @var list<string> $addresses */
        $addresses = array_values(array_filter($value, is_string(...)));

        return $addresses === [] ? null : $addresses;
    }
}
