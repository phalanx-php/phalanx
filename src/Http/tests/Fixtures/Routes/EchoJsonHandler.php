<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Fixtures\Routes;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Phalanx\Http\RequestContext;
use Phalanx\Task\Executable;
use Psr\Http\Message\ResponseInterface;

final class EchoJsonHandler implements Executable
{
    public function __invoke(RequestContext $ctx): ResponseInterface
    {
        $request = $ctx->request;
        $body = (string) $request->getBody();
        $payload = $body === '' ? [] : json_decode($body, true);

        return new Response(
            201,
            ['Content-Type' => 'application/json'],
            Utils::streamFor(json_encode([
                'received' => $payload,
                'identity' => $request->getAttribute('identity'),
            ], JSON_THROW_ON_ERROR)),
        );
    }
}
