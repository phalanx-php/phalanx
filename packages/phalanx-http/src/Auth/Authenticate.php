<?php

declare(strict_types=1);

namespace Phalanx\Http\Auth;

use Closure;
use Phalanx\Auth\AuthenticationException;
use Phalanx\Auth\Guard;
use Phalanx\Http\AuthenticatedExecutionContext;
use Phalanx\Http\Contract\Middleware;
use Phalanx\Http\RequestScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

final class Authenticate implements Middleware, Executable
{
    public function __construct(private readonly Guard $guard) {}

    public function handle(RequestScope $scope, Closure $next): mixed
    {
        $auth = $this->guard->authenticate($scope->request);

        if ($auth === null) {
            throw new AuthenticationException();
        }

        return $next(new AuthenticatedExecutionContext($scope, $auth));
    }

    public function __invoke(RequestScope $scope): mixed
    {
        /** @var Scopeable|Executable $next */
        $next = $scope->attribute('handler.next');

        return $this->handle(
            $scope,
            static function (RequestScope $s) use ($next): mixed {
                return $next($s);
            },
        );
    }
}
