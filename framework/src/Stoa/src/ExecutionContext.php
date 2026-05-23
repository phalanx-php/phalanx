<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Phalanx\Exception\ServiceNotFoundException;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Support\ExecutionScopeDelegate;
use Psr\Http\Message\ServerRequestInterface;

class ExecutionContext implements RequestScope
{
    use ExecutionScopeDelegate;

    private ?RequestBody $requestBody = null;

    public RequestCtx $ctx {
        get => $this->requestCtx ??= $this->service(RequestCtx::class);
    }

    public string $requestId {
        get => $this->requestResource()->id;
    }

    public RequestBody $body {
        get => $this->requestBody ??= RequestBody::from($this->request);
    }

    public function __construct(
        private(set) ExecutionScope $inner,
        private(set) ServerRequestInterface $request,
        private(set) RouteParams $params,
        private(set) QueryParams $query,
        private(set) RouteConfig $config,
        private ?RequestCtx $requestCtx = null,
    ) {
    }

    public function method(): string
    {
        return $this->request->getMethod();
    }

    public function path(): string
    {
        return $this->request->getUri()->getPath();
    }

    public function header(string $name): string
    {
        return $this->request->getHeaderLine($name);
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
        return (string) ($this->request->getServerParams()[$key] ?? $default);
    }

    public function clientIp(): string
    {
        $forwarded = $this->header('X-Forwarded-For');

        if ($forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }

        return $this->server('REMOTE_ADDR', '0.0.0.0');
    }

    protected function innerScope(): ExecutionScope
    {
        return $this->inner;
    }

    private function requestResource(): StoaRequestResource
    {
        try {
            return $this->service(StoaRequestResource::class);
        } catch (ServiceNotFoundException) {
            throw MissingRequestResource::forScopeKey(StoaRequestResource::class);
        }
    }
}
