<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Phalanx\ExecutionScope;
use Psr\Http\Message\ServerRequestInterface;

interface RequestScope extends ExecutionScope
{
    public ServerRequestInterface $request { get; }
    public RouteParams $params { get; }
    public QueryParams $query { get; }
    public RequestBody $body { get; }
    public RouteConfig $config { get; }

    public function method(): string;

    public function path(): string;

    public function header(string $name): string;

    public function isJson(): bool;

    public function bearerToken(): ?string;

    public function server(string $key, string $default = ''): string;

    public function clientIp(): string;
}
