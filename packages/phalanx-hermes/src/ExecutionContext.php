<?php

declare(strict_types=1);

namespace Phalanx\Hermes;

use Phalanx\Scope\ExecutionScope as BaseExecutionScope;
use Phalanx\Stoa\RequestCtx;
use Phalanx\Stoa\RouteParams;
use Phalanx\Support\ExecutionScopeDelegate;
use Psr\Http\Message\ServerRequestInterface;

class ExecutionContext implements WsScope
{
    use ExecutionScopeDelegate;

    public function __construct(
        private readonly BaseExecutionScope $inner,
        private(set) WsConnection $connection,
        private(set) WsConfig $config,
        private(set) ServerRequestInterface $request,
        private(set) RouteParams $params,
        private(set) RequestCtx $ctx,
    ) {
    }

    protected function innerScope(): BaseExecutionScope
    {
        return $this->inner;
    }
}
