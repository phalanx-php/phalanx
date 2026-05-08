<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Auth;

use Phalanx\Auth\AuthenticationException;
use Phalanx\Auth\Guard;
use Phalanx\Hermes\AuthExecutionContext;
use Phalanx\Hermes\WsScope;
use Phalanx\Task\Executable;
use RuntimeException;

final class Authenticate implements Executable
{
    public function __construct(
        private readonly Guard $guard,
    ) {
    }

    public function __invoke(WsScope $scope): mixed
    {
        $auth = $this->guard->authenticate($scope->request);

        if ($auth === null) {
            throw new AuthenticationException();
        }

        $next = $scope->attribute('handler.next');
        if (!is_callable($next)) {
            throw new RuntimeException('Authenticated WebSocket handler is not callable.');
        }

        $authenticated = new AuthExecutionContext($scope, $auth);

        return $next($authenticated);
    }
}
