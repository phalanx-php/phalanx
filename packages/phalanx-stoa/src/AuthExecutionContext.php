<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Phalanx\Auth\AuthContext;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Support\ExecutionScopeDelegate;
use Psr\Http\Message\ServerRequestInterface;

class AuthExecutionContext implements AuthRequestScope
{
    use ExecutionScopeDelegate;

    public RequestCtx $ctx {
        get => $this->inner->ctx;
    }

    public string $resourceId {
        get => $this->inner->resourceId;
    }

    public StoaRequestResource $requestResource {
        get => $this->inner->requestResource;
    }

    public StoaRequestDiagnostics $diagnostics {
        get => $this->inner->diagnostics;
    }

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

    public function acceptsHtml(): bool
    {
        return $this->inner->acceptsHtml();
    }

    public function bearerToken(): ?string
    {
        return $this->inner->bearerToken();
    }

    public function server(string $key, string $default = ''): string
    {
        return $this->inner->server($key, $default);
    }

    public function clientIp(): string
    {
        return $this->inner->clientIp();
    }

    protected function innerScope(): ExecutionScope
    {
        return $this->inner;
    }
}
