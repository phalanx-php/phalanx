<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb\Tests\Unit;

use Phalanx\Scope\Scope;
use Phalanx\Scope\Suspendable;

final class FakeTransport implements \Phalanx\SurrealDb\Transport
{
    /** @var list<array{method: string, params: list<mixed>, namespace: string, database: string, token: ?string}> */
    public array $calls = [];

    /** @param list<mixed> $responses */
    public function __construct(
        private array $responses,
    ) {
    }

    public function rpc(
        Scope&Suspendable $scope,
        \Phalanx\SurrealDb\Config $config,
        ?string $token,
        string $method,
        array $params = [],
    ): mixed {
        $this->calls[] = [
            'method' => $method,
            'params' => $params,
            'namespace' => $config->namespace,
            'database' => $config->database,
            'token' => $token,
        ];

        return array_shift($this->responses);
    }

    public function status(Scope&Suspendable $scope, \Phalanx\SurrealDb\Config $config, ?string $token): int
    {
        $this->calls[] = [
            'method' => 'status',
            'params' => [],
            'namespace' => $config->namespace,
            'database' => $config->database,
            'token' => $token,
        ];

        return 200;
    }

    public function health(Scope&Suspendable $scope, \Phalanx\SurrealDb\Config $config, ?string $token): int
    {
        $this->calls[] = [
            'method' => 'health',
            'params' => [],
            'namespace' => $config->namespace,
            'database' => $config->database,
            'token' => $token,
        ];

        return 200;
    }
}
