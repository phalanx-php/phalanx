<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Phalanx\Scope\ExecutionScope;
use Psr\Http\Message\ServerRequestInterface;

interface RequestScope extends ExecutionScope
{
    public string $resourceId { get; }
    public ServerRequestInterface $request { get; }
    public RouteParams $params { get; }
    public QueryParams $query { get; }
    public RequestBody $body { get; }
    public RouteConfig $config { get; }

    public function method(): string;

    public function path(): string;

    public function header(string $name): string;

    public function isJson(): bool;

    public function acceptsHtml(): bool;

    public function bearerToken(): ?string;

    public function server(string $key, string $default = ''): string;

    public function clientIp(): string;
}
