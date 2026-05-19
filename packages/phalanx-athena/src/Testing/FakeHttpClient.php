<?php

declare(strict_types=1);

namespace Phalanx\Athena\Testing;

use Phalanx\Iris\HttpClient;
use Phalanx\Iris\HttpRequest;
use Phalanx\Iris\HttpResponse;
use Phalanx\Iris\HttpStream;
use Phalanx\Scope\Scope;
use Phalanx\Scope\Suspendable;

/**
 * Deterministic HttpClient replacement for tests and demos alike.
 *
 * Promoted to public package surface (Phalanx\Athena\Testing) so Athena's
 * own unit tests AND demo scripts can share the same offline SSE simulation
 * without each carrying a private copy.
 *
 * stream() always returns the configured FakeHttpStream.
 * post() pops and returns the next queued HttpResponse; if the queue is
 * exhausted it returns a generic 202 Accepted.
 *
 * Constructed without any live Aegis runtime or OpenSwoole dependencies — safe
 * to use in unit tests, acceptance tests, and demo scripts alike.
 */
final class FakeHttpClient extends HttpClient
{
    /** @var list<HttpResponse> */
    private array $postQueue = [];

    /** @var list<array{string, string, string, array<string, list<string>>}> */
    private array $postedRequests = [];

    public function __construct(
        private HttpStream $sseStream,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function stream(Scope&Suspendable $scope, HttpRequest $request): HttpStream
    {
        return $this->sseStream;
    }

    /** @param array<string, list<string>> $headers */
    #[\Override]
    public function post(Scope&Suspendable $scope, string $url, string $body, array $headers = []): HttpResponse
    {
        $this->postedRequests[] = ['POST', $url, $body, $headers];

        if ($this->postQueue !== []) {
            return array_shift($this->postQueue);
        }

        return new HttpResponse(202, 'Accepted', [], '');
    }

    public function queuePostResponse(HttpResponse $response): void
    {
        $this->postQueue[] = $response;
    }

    /** @return list<array{string, string, string, array<string, list<string>>}> */
    public function postedRequests(): array
    {
        return $this->postedRequests;
    }
}
