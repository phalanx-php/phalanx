<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use Phalanx\Auth\AuthContext;
use Phalanx\Http\RouteParams;
use Phalanx\Scope\ExecutionScope as BaseExecutionScope;
use Phalanx\Support\ExecutionScopeDelegate;
use Psr\Http\Message\ServerRequestInterface;

class AuthExecutionContext implements \Phalanx\WebSocket\AuthenticatedContext
{
    use ExecutionScopeDelegate;

    public \Phalanx\WebSocket\Connection $connection {
        get => $this->wsContext->connection;
    }

    public \Phalanx\WebSocket\Config $config {
        get => $this->wsContext->config;
    }

    public ServerRequestInterface $request {
        get => $this->wsContext->request;
    }

    public RouteParams $params {
        get => $this->wsContext->params;
    }

    public function __construct(
        private(set) \Phalanx\WebSocket\Context $wsContext,
        private(set) AuthContext $auth,
    ) {
    }

    protected function innerScope(): BaseExecutionScope
    {
        return $this->wsContext;
    }
}
