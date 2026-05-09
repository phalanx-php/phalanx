<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Realtime\Routes;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Phalanx\Iris\Iris;
use Phalanx\Stoa\RequestScope;
use Phalanx\Task\Scopeable;
use Psr\Http\Message\ResponseInterface;

final class Proxy implements Scopeable
{
    public function __invoke(RequestScope $scope): ResponseInterface
    {
        $upstreamPort = (int) ($scope->query->get('upstream_port') ?? '0');
        $client = Iris::client($scope);

        $upstream = $client->get($scope, "http://127.0.0.1:{$upstreamPort}/realtime/health");

        return new PsrResponse(
            $upstream->status,
            ['Content-Type' => 'application/json'],
            $upstream->body,
        );
    }
}
