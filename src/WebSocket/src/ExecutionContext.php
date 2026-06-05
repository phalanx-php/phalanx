<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use Phalanx\Http\RouteParams;
use Phalanx\Scope\ExecutionScope as BaseExecutionScope;
use Phalanx\Support\ExecutionScopeDelegate;
use Psr\Http\Message\ServerRequestInterface;

class ExecutionContext implements \Phalanx\WebSocket\Context
{
    use ExecutionScopeDelegate;

    public function __construct(
        private(set) BaseExecutionScope $inner,
        private(set) \Phalanx\WebSocket\Connection $connection,
        private(set) \Phalanx\WebSocket\Config $config,
        private(set) ServerRequestInterface $request,
        private(set) RouteParams $params,
    ) {
    }

    protected function innerScope(): BaseExecutionScope
    {
        return $this->inner;
    }
}
