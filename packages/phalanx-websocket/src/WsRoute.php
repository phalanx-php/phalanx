<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use Closure;
use Phalanx\Scope;
use Phalanx\Task\Scopeable;

final readonly class WsRoute implements Scopeable
{
    /** @param Closure(WsScope): void $fn */
    public function __construct(public Closure $fn, public WsConfig $config = new WsConfig())
    {
    }

    public function __invoke(Scope $scope): mixed
    {
        assert($scope instanceof WsScope);
        ($this->fn)($scope);
        return null;
    }
}
