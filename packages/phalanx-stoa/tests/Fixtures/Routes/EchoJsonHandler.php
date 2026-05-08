<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Fixtures\Routes;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class EchoJsonHandler implements Executable
{
    public function __invoke(ExecutionScope $scope): ResponseInterface
    {
        $request = $scope->attribute('request');

        if (!$request instanceof ServerRequestInterface) {
            return new Response(500, [], 'no request in scope');
        }

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
