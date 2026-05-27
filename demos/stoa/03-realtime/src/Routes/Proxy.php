<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Realtime\Routes;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Phalanx\Iris\Iris;
use Phalanx\Stoa\RequestContext;
use Phalanx\Task\Scopeable;
use Psr\Http\Message\ResponseInterface;

final class Proxy implements Scopeable
{
    public function __invoke(RequestContext $ctx): ResponseInterface
    {
        $upstreamPort = (int) ($ctx->query->get('upstream_port') ?? '0');
        $client = Iris::client($ctx);

        $upstream = $client->get($ctx, "http://127.0.0.1:{$upstreamPort}/realtime/health");

        return new PsrResponse(
            $upstream->status,
            ['Content-Type' => 'application/json'],
            $upstream->body,
        );
    }
}
