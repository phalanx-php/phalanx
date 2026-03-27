<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Phalanx\ExecutionScope;
use Psr\Http\Message\ServerRequestInterface;

interface RequestScope extends ExecutionScope
{
    public ServerRequestInterface $request { get; }
    public RouteParams $params { get; }
    public QueryParams $query { get; }
    public RouteConfig $config { get; }
}
