<?php

declare(strict_types=1);

namespace BgAgents\Daemon8;

use BgAgents\Config\BgAgentsConfig;
use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use React\Promise\PromiseInterface;

/**
 * Thin async wrapper around the daemon8 HTTP API for non-SSE traffic.
 *
 * Streaming subscriptions are handled by Phalanx\Athena\Swarm\Daemon8SwarmBus.
 * This client owns short-lived request/response calls: health, checkpoint,
 * observation queries, and direct ingest.
 *
 * All methods return PromiseInterface; callers wrap with $scope->await(...)
 * so cancellation racing happens through the scope, never raw await.
 */
final class ObservationClient
{
    private readonly Browser $browser;

    public function __construct(
        private readonly BgAgentsConfig $config,
    ) {
        $this->browser = (new Browser())
            ->withTimeout(15.0)
            ->withFollowRedirects(false)
            ->withRejectErrorResponse(false);
    }

    /** @return PromiseInterface<bool> */
    public function health(): PromiseInterface
    {
        return $this->browser->get("{$this->config->daemon8Url}/health")
            ->then(static fn(ResponseInterface $r): bool => $r->getStatusCode() === 200);
    }

    /** @return PromiseInterface<int> */
    public function checkpoint(): PromiseInterface
    {
        return $this->browser->get("{$this->config->daemon8Url}/api/checkpoint")
            ->then(static function (ResponseInterface $r): int {
                if ($r->getStatusCode() !== 200) {
                    throw new \RuntimeException("daemon8 /api/checkpoint returned {$r->getStatusCode()}");
                }
                $body = (string) $r->getBody();
                $decoded = json_decode($body, true);
                if (!is_array($decoded) || !isset($decoded['checkpoint']) || !is_int($decoded['checkpoint'])) {
                    throw new \RuntimeException('daemon8 /api/checkpoint returned malformed body: ' . $body);
                }
                return $decoded['checkpoint'];
            });
    }

    /**
     * @return PromiseInterface<array{observations: list<ObservationRecord>, checkpoint: int}>
     */
    public function observe(ObservationQuery $query): PromiseInterface
    {
        $url = "{$this->config->daemon8Url}/api/observe?{$query->toQueryString()}";

        return $this->browser->get($url)
            ->then(static function (ResponseInterface $r): array {
                if ($r->getStatusCode() !== 200) {
                    throw new \RuntimeException("daemon8 /api/observe returned {$r->getStatusCode()}");
                }
                $body = (string) $r->getBody();
                $decoded = json_decode($body, true);
                if (!is_array($decoded)) {
                    throw new \RuntimeException('daemon8 /api/observe returned malformed body');
                }

                $rows = $decoded['observations'] ?? [];
                $records = [];
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        if (is_array($row)) {
                            $records[] = ObservationRecord::fromRow($row);
                        }
                    }
                }

                $checkpoint = isset($decoded['checkpoint']) && is_int($decoded['checkpoint'])
                    ? $decoded['checkpoint']
                    : 0;

                return ['observations' => $records, 'checkpoint' => $checkpoint];
            });
    }

    /**
     * Fire-and-forget ingest. Use SwarmBus::emit() for swarm-message events;
     * use this only when you specifically need a non-swarm channel
     * (e.g. memory records, custom telemetry).
     *
     * @param array<string, mixed> $payload  full ingest envelope (kind, data, channel, tags, ...)
     * @return PromiseInterface<bool>
     */
    public function ingest(array $payload): PromiseInterface
    {
        return $this->browser->post(
            "{$this->config->daemon8Url}/ingest",
            ['Content-Type' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        )->then(static fn(ResponseInterface $r): bool => $r->getStatusCode() < 300);
    }
}
