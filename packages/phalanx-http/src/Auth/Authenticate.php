<?php

declare(strict_types=1);

namespace Phalanx\Http\Auth;

use Phalanx\Auth\AuthenticationException;
use Phalanx\Auth\Guard;
use Phalanx\ExecutionScope;
use Phalanx\Http\AuthenticatedExecutionContext;
use Phalanx\Http\RequestScope;
use Phalanx\Task\Executable;

final class Authenticate implements Executable
{
    public function __construct(
        private readonly Guard $guard,
    ) {}

    public function __invoke(ExecutionScope $scope): mixed
    {
        assert($scope instanceof RequestScope);

        $auth = $this->guard->resolve($scope->request);

        if ($auth === null) {
            throw new AuthenticationException();
        }

        /** @var Executable $next */
        $next = $scope->attribute('handler.next');
        $authenticated = new AuthenticatedExecutionContext($scope, $auth);

        return ($next)($authenticated);
    }
}
