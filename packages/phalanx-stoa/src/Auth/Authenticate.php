<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Auth;

use Closure;
use Phalanx\Auth\AuthenticationException;
use Phalanx\Auth\Guard;
use Phalanx\Stoa\AuthExecutionContext;
use Phalanx\Stoa\Contract\Middleware;
use Phalanx\Stoa\RequestScope;

final class Authenticate implements Middleware
{
    public function __construct(private readonly Guard $guard)
    {
    }

    public function __invoke(RequestScope $scope, Closure $next): mixed
    {
        $auth = $this->guard->authenticate($scope->request);

        if ($auth === null) {
            throw new AuthenticationException();
        }

        return $next(new AuthExecutionContext($scope, $auth));
    }
}
