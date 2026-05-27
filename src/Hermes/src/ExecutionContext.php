<?php

declare(strict_types=1);

namespace Phalanx\Hermes;

use Phalanx\Scope\ExecutionScope as BaseExecutionScope;
use Phalanx\Stoa\RouteParams;
use Phalanx\Support\ExecutionScopeDelegate;
use Psr\Http\Message\ServerRequestInterface;

class ExecutionContext implements WsContext
{
    use ExecutionScopeDelegate;

    public function __construct(
        private(set) BaseExecutionScope $inner,
        private(set) WsConnection $connection,
        private(set) WsConfig $config,
        private(set) ServerRequestInterface $request,
        private(set) RouteParams $params,
    ) {
    }

    protected function innerScope(): BaseExecutionScope
    {
        return $this->inner;
    }
}
