<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Phalanx\Auth\AuthContext;
use Phalanx\ExecutionScope;
use Phalanx\Support\ExecutionScopeDelegate;
use Psr\Http\Message\ServerRequestInterface;

final class AuthenticatedExecutionContext implements AuthenticatedRequestScope
{
    use ExecutionScopeDelegate;

    public ServerRequestInterface $request {
        get => $this->inner->request;
    }

    public RouteParams $params {
        get => $this->inner->params;
    }

    public QueryParams $query {
        get => $this->inner->query;
    }

    public RequestBody $body {
        get => $this->inner->body;
    }

    public RouteConfig $config {
        get => $this->inner->config;
    }

    public AuthContext $auth {
        get => $this->authContext;
    }

    public function __construct(
        private readonly RequestScope $inner,
        private readonly AuthContext $authContext,
    ) {
    }

    public function method(): string
    {
        return $this->inner->method();
    }

    public function path(): string
    {
        return $this->inner->path();
    }

    public function header(string $name): string
    {
        return $this->inner->header($name);
    }

    public function isJson(): bool
    {
        return $this->inner->isJson();
    }

    public function bearerToken(): ?string
    {
        return $this->inner->bearerToken();
    }

    public function withAttribute(string $key, mixed $value): AuthenticatedRequestScope
    {
        /** @var RequestScope $newInner */
        $newInner = $this->inner->withAttribute($key, $value);

        return new self($newInner, $this->authContext);
    }

    protected function innerScope(): ExecutionScope
    {
        return $this->inner;
    }
}
