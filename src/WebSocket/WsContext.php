<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Http\RouteParams;
use Psr\Http\Message\ServerRequestInterface;

interface WsContext extends ExecutionScope
{
    public WsConnection $connection { get; }
    public WsConfig $config { get; }
    public ServerRequestInterface $request { get; }
    public RouteParams $params { get; }
}
