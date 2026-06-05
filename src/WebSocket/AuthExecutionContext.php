<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use Phalanx\Auth\AuthContext;
use Phalanx\Scope\ExecutionScope as BaseExecutionScope;
use Phalanx\Http\RouteParams;
use Phalanx\Support\ExecutionScopeDelegate;
use Psr\Http\Message\ServerRequestInterface;

class AuthExecutionContext implements AuthWsContext
{
    use ExecutionScopeDelegate;

    public WsConnection $connection {
        get => $this->wsContext->connection;
    }

    public WsConfig $config {
        get => $this->wsContext->config;
    }

    public ServerRequestInterface $request {
        get => $this->wsContext->request;
    }

    public RouteParams $params {
        get => $this->wsContext->params;
    }

    public function __construct(
        private(set) WsContext $wsContext,
        private(set) AuthContext $auth,
    ) {
    }

    protected function innerScope(): BaseExecutionScope
    {
        return $this->wsContext;
    }
}
