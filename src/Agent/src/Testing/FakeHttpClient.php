<?php

declare(strict_types=1);

namespace Phalanx\Agent\Testing;

use Phalanx\Scope\Scope;
use Phalanx\Scope\Suspendable;

/**
 * Deterministic HttpClient replacement for tests and demos alike.
 *
 * Promoted to public package surface (Phalanx\Agent\Testing) so Agent's
 * own unit tests AND demo scripts can share the same offline SSE simulation
 * without each carrying a private copy.
 *
 * stream() always returns the configured FakeHttpStream.
 * post() pops and returns the next queued HttpResponse; if the queue is
 * exhausted it returns a generic 202 Accepted.
 *
 * Constructed without any live Runtime runtime or Swoole dependencies — safe
 * to use in unit tests, acceptance tests, and demo scripts alike.
 */
final class FakeHttpClient extends \Phalanx\HttpClient\Client
{
    /** @var list<\Phalanx\HttpClient\Response> */
    private array $postQueue = [];

    /** @var list<array{string, string, string, array<string, list<string>>}> */
    private array $postedRequests = [];

    public function __construct(
        private readonly \Phalanx\HttpClient\Stream $sseStream,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function stream(Scope&Suspendable $scope, \Phalanx\HttpClient\Request $request): \Phalanx\HttpClient\Stream
    {
        return $this->sseStream;
    }

    /** @param array<string, list<string>> $headers */
    #[\Override]
    public function post(Scope&Suspendable $scope, string $url, string $body, array $headers = []): \Phalanx\HttpClient\Response
    {
        $this->postedRequests[] = ['POST', $url, $body, $headers];

        if ($this->postQueue !== []) {
            return array_shift($this->postQueue);
        }

        return new \Phalanx\HttpClient\Response(202, 'Accepted', [], '');
    }

    public function queuePostResponse(\Phalanx\HttpClient\Response $response): void
    {
        $this->postQueue[] = $response;
    }

    /** @return list<array{string, string, string, array<string, list<string>>}> */
    public function postedRequests(): array
    {
        return $this->postedRequests;
    }
}
