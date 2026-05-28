<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Fixtures;

use AegisSwoole\Concurrency\RetryPolicy;
use AegisSwoole\Http\HttpClient;
use AegisSwoole\Http\HttpResponse;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Task\Executable;
use AegisSwoole\Task\Retryable;
use RuntimeException;

class HttpRetryableTask implements Executable, Retryable
{
    public int $attempts = 0;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $path,
        private readonly RetryPolicy $policy,
    ) {
    }

    public function retryPolicy(): RetryPolicy
    {
        return $this->policy;
    }

    public function __invoke(ExecutionScope $scope): HttpResponse
    {
        $this->attempts++;
        $client = $scope->service(HttpClient::class);
        $resp = $client->get($this->host, $this->port, $this->path);
        if (!$resp->ok()) {
            throw new RuntimeException("http {$resp->statusCode}");
        }
        return $resp;
    }
}
