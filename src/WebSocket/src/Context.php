<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use Phalanx\Http\RouteParams;
use Phalanx\Scope\ExecutionScope;
use Psr\Http\Message\ServerRequestInterface;

interface Context extends ExecutionScope
{
    public \Phalanx\WebSocket\Connection $connection { get; }
    public \Phalanx\WebSocket\Config $config { get; }
    public ServerRequestInterface $request { get; }
    public RouteParams $params { get; }
}
