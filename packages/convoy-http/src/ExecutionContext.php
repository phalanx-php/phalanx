<?php

declare(strict_types=1);

namespace Convoy\Http;

use Convoy\ExecutionScope;
use Convoy\Support\ExecutionScopeDelegate;
use Psr\Http\Message\ServerRequestInterface;

final class ExecutionContext implements RequestScope
{
    use ExecutionScopeDelegate;

    public ServerRequestInterface $request {
        get => $this->serverRequest;
    }

    public RouteParams $params {
        get => $this->routeParams;
    }

    public QueryParams $query {
        get => $this->queryParams;
    }

    public RouteConfig $config {
        get => $this->routeConfig;
    }

    public function __construct(
        private readonly ExecutionScope $inner,
        private readonly ServerRequestInterface $serverRequest,
        private readonly RouteParams $routeParams,
        private readonly QueryParams $queryParams,
        private readonly RouteConfig $routeConfig,
    ) {
    }

    public function withAttribute(string $key, mixed $value): RequestScope
    {
        return new self(
            $this->inner->withAttribute($key, $value),
            $this->serverRequest,
            $this->routeParams,
            $this->queryParams,
            $this->routeConfig,
        );
    }

    protected function innerScope(): ExecutionScope
    {
        return $this->inner;
    }
}
