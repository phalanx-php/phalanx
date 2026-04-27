<?php

declare(strict_types=1);

namespace Phalanx\Hermes;

use Phalanx\Auth\AuthContext;
use Phalanx\ExecutionScope as BaseExecutionScope;
use Phalanx\Stoa\RouteParams;
use Phalanx\Support\ExecutionScopeDelegate;
use Psr\Http\Message\ServerRequestInterface;

class AuthExecutionContext implements AuthWsScope
{
    use ExecutionScopeDelegate;

    public WsConnection $connection {
        get => $this->wsScope->connection;
    }

    public WsConfig $config {
        get => $this->wsScope->config;
    }

    public ServerRequestInterface $request {
        get => $this->wsScope->request;
    }

    public RouteParams $params {
        get => $this->wsScope->params;
    }

    public AuthContext $auth {
        get => $this->authContext;
    }

    public function __construct(
        private readonly WsScope $wsScope,
        private readonly AuthContext $authContext,
    ) {
    }

    public function withAttribute(string $key, mixed $value): AuthWsScope
    {
        /** @var WsScope $newInner */
        $newInner = $this->wsScope->withAttribute($key, $value);

        return new self($newInner, $this->authContext);
    }

    protected function innerScope(): BaseExecutionScope
    {
        return $this->wsScope;
    }
}
