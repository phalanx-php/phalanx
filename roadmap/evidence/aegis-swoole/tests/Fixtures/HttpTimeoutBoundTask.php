<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Fixtures;

use AegisSwoole\Http\HttpClient;
use AegisSwoole\Http\HttpResponse;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Task\Executable;
use AegisSwoole\Task\HasTimeout;

class HttpTimeoutBoundTask implements Executable, HasTimeout
{
    public function __construct(
        private readonly float $timeout,
        private readonly string $host,
        private readonly int $port,
        private readonly string $path,
    ) {
    }

    public function timeoutSeconds(): float
    {
        return $this->timeout;
    }

    public function __invoke(ExecutionScope $scope): HttpResponse
    {
        $client = $scope->service(HttpClient::class);
        return $client->get($this->host, $this->port, $this->path, timeout: 30.0);
    }
}
