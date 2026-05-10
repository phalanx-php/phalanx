<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Stoa\Runtime\StoaScopeKey;
use Phalanx\Support\ExecutionScopeDelegate;
use Psr\Http\Message\ServerRequestInterface;

class ExecutionContext implements RequestScope
{
    use ExecutionScopeDelegate;

    private ?RequestBody $requestBody = null;

    public string $resourceId {
        get {
            $id = $this->attribute(StoaScopeKey::ResourceId->value);

            if (!is_string($id) || $id === '') {
                throw MissingRequestResource::forScopeKey(StoaScopeKey::ResourceId->value);
            }

            return $id;
        }
    }

    public ServerRequestInterface $request {
        get => $this->serverRequest;
    }

    public RouteParams $params {
        get => $this->routeParams;
    }

    public QueryParams $query {
        get => $this->queryParams;
    }

    public RequestBody $body {
        get => $this->requestBody ??= RequestBody::from($this->serverRequest);
    }

    public RouteConfig $config {
        get => $this->routeConfig;
    }

    public function __construct(
        private(set) ExecutionScope $inner,
        private(set) ServerRequestInterface $serverRequest,
        private(set) RouteParams $routeParams,
        private(set) QueryParams $queryParams,
        private(set) RouteConfig $routeConfig,
    ) {
    }

    public function method(): string
    {
        return $this->serverRequest->getMethod();
    }

    public function path(): string
    {
        return $this->serverRequest->getUri()->getPath();
    }

    public function header(string $name): string
    {
        return $this->serverRequest->getHeaderLine($name);
    }

    public function isJson(): bool
    {
        return str_contains($this->header('Content-Type'), 'application/json');
    }

    public function acceptsHtml(): bool
    {
        return str_contains($this->header('Accept'), 'text/html');
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization');

        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return substr($header, 7);
    }

    public function server(string $key, string $default = ''): string
    {
        return (string) ($this->serverRequest->getServerParams()[$key] ?? $default);
    }

    public function clientIp(): string
    {
        $forwarded = $this->header('X-Forwarded-For');

        if ($forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }

        return $this->server('REMOTE_ADDR', '0.0.0.0');
    }

    public function withAttribute(string $key, mixed $value): RequestScope
    {
        $ctx = new self(
            $this->inner->withAttribute($key, $value),
            $this->serverRequest,
            $this->routeParams,
            $this->queryParams,
            $this->routeConfig,
        );

        $ctx->requestBody = $this->requestBody;

        return $ctx;
    }

    protected function innerScope(): ExecutionScope
    {
        return $this->inner;
    }
}
