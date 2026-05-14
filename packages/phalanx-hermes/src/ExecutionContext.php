<?php

declare(strict_types=1);

namespace Phalanx\Hermes;

use Phalanx\Scope\ExecutionScope as BaseExecutionScope;
use Phalanx\Stoa\RequestCtx;
use Phalanx\Stoa\RouteParams;
use Phalanx\Support\ExecutionScopeDelegate;
use Psr\Http\Message\ServerRequestInterface;

class ExecutionContext implements WsScope
{
    use ExecutionScopeDelegate;

    public RequestCtx $ctx {
        get => $this->requestCtx;
    }

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
        private readonly RequestCtx $requestCtx,
    ) {
    }

    protected function innerScope(): BaseExecutionScope
    {
        return $this->inner;
    }
}
