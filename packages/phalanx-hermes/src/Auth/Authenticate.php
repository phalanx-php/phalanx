<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Auth;

use Phalanx\Auth\AuthenticationException;
use Phalanx\Auth\Guard;
use Phalanx\Task\Executable;
use Phalanx\Hermes\AuthExecutionContext;
use Phalanx\Hermes\WsScope;

final class Authenticate implements Executable
{
    public function __construct(
        private readonly Guard $guard,
    ) {}

    public function __invoke(WsScope $scope): mixed
    {
        $auth = $this->guard->authenticate($scope->request);

        if ($auth === null) {
            throw new AuthenticationException();
        }

        /** @var Executable $next */
        $next = $scope->attribute('handler.next');
        $authenticated = new AuthExecutionContext($scope, $auth);

        return ($next)($authenticated);
    }
}
