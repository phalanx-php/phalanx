<?php

declare(strict_types=1);

namespace Convoy\WebSocket;

use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Handshake\ServerNegotiator;

final readonly class WsHandshake
{
    private ServerNegotiator $negotiator;

    /** @param list<string> $subprotocols */
    public function __construct(array $subprotocols = [])
    {
        $this->negotiator = new ServerNegotiator(
            new RequestVerifier(),
            new HttpFactory(),
        );

        if ($subprotocols !== []) {
            $this->negotiator->setSupportedSubProtocols($subprotocols);
        }
    }

    public function negotiate(RequestInterface $request): ResponseInterface
    {
        return $this->negotiator->handshake($request);
    }

    public function isSuccessful(ResponseInterface $response): bool
    {
        return $response->getStatusCode() === 101;
    }
}
