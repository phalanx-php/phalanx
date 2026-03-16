<?php

declare(strict_types=1);

namespace Convoy\Http;

use Convoy\ExecutionScope;
use Psr\Http\Message\ServerRequestInterface;

interface RequestScope extends ExecutionScope
{
    public ServerRequestInterface $request { get; }
    public RouteParams $params { get; }
    public QueryParams $query { get; }
    public RouteConfig $config { get; }
}
