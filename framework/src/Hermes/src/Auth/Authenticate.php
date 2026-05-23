<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Auth;

use Closure;
use Phalanx\Auth\AuthenticationException;
use Phalanx\Auth\Guard;
use Phalanx\Hermes\AuthExecutionContext;
use Phalanx\Hermes\WsScope;
use Phalanx\Task\Executable;

final class Authenticate implements Executable
{
    public function __construct(
        private readonly Guard $guard,
    ) {
    }

    /** @param Closure(WsScope): mixed $next */
    public function __invoke(WsScope $scope, Closure $next): mixed
    {
        $auth = $this->guard->authenticate($scope->request);

        if ($auth === null) {
            throw new AuthenticationException();
        }

        $authenticated = new AuthExecutionContext($scope, $auth);

        return $next($authenticated);
    }
}
