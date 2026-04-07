<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Auth;

use Phalanx\Auth\AuthenticationException;
use Phalanx\Auth\Guard;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\WebSocket\AuthenticatedWsScope;
use Phalanx\WebSocket\WsScope;

final class Authenticate implements Executable
{
    public function __construct(
        private readonly Guard $guard,
    ) {}

    public function __invoke(ExecutionScope $scope): mixed
    {
        assert($scope instanceof WsScope);

        $auth = $this->guard->authenticate($scope->request);

        if ($auth === null) {
            throw new AuthenticationException();
        }

        /** @var Executable $next */
        $next = $scope->attribute('handler.next');
        $authenticated = new AuthenticatedWsScope($scope, $auth);

        return ($next)($authenticated);
    }
}
