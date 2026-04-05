<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use Phalanx\ExecutionScope;
use Phalanx\Http\RouteParams;
use Phalanx\Support\ExecutionScopeDelegate;
use Psr\Http\Message\ServerRequestInterface;

final class WsScope implements ExecutionScope
{
    use ExecutionScopeDelegate;

    public WsConnection $connection {
        get => $this->conn;
    }

    public WsConfig $config {
        get => $this->wsConfig;
    }

    public ServerRequestInterface $request {
        get => $this->upgradeRequest;
    }

    public RouteParams $params {
        get => $this->routeParams;
    }

    public function __construct(
        private readonly ExecutionScope $inner,
        private readonly WsConnection $conn,
        private readonly WsConfig $wsConfig,
        private readonly ServerRequestInterface $upgradeRequest,
        private readonly RouteParams $routeParams,
    ) {
    }

    public function withAttribute(string $key, mixed $value): ExecutionScope
    {
        return new self(
            $this->inner->withAttribute($key, $value),
            $this->conn,
            $this->wsConfig,
            $this->upgradeRequest,
            $this->routeParams,
        );
    }

    protected function innerScope(): ExecutionScope
    {
        return $this->inner;
    }
}
