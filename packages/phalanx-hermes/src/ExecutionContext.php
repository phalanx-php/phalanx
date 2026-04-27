<?php

declare(strict_types=1);

namespace Phalanx\Hermes;

use Phalanx\ExecutionScope as BaseExecutionScope;
use Phalanx\Stoa\RouteParams;
use Phalanx\Support\ExecutionScopeDelegate;
use Psr\Http\Message\ServerRequestInterface;

class ExecutionContext implements WsScope
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
        private readonly BaseExecutionScope $inner,
        private readonly WsConnection $conn,
        private readonly WsConfig $wsConfig,
        private readonly ServerRequestInterface $upgradeRequest,
        private readonly RouteParams $routeParams,
    ) {
    }

    public function withAttribute(string $key, mixed $value): WsScope
    {
        return new self(
            $this->inner->withAttribute($key, $value),
            $this->conn,
            $this->wsConfig,
            $this->upgradeRequest,
            $this->routeParams,
        );
    }

    protected function innerScope(): BaseExecutionScope
    {
        return $this->inner;
    }
}
