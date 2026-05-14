<?php

declare(strict_types=1);

namespace Phalanx\Hermes;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Stoa\RequestCtx;
use Phalanx\Stoa\RouteParams;
use Psr\Http\Message\ServerRequestInterface;

interface WsScope extends ExecutionScope
{
    public RequestCtx $ctx { get; }
    public WsConnection $connection { get; }
    public WsConfig $config { get; }
    public ServerRequestInterface $request { get; }
    public RouteParams $params { get; }
}
