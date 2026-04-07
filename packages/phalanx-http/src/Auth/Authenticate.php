<?php

declare(strict_types=1);

namespace Phalanx\Http\Auth;

use Closure;
use Phalanx\Auth\AuthenticationException;
use Phalanx\Auth\Guard;
use Phalanx\ExecutionScope;
use Phalanx\Http\AuthenticatedExecutionContext;
use Phalanx\Http\Contract\Middleware;
use Phalanx\Http\RequestScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

/**
 * Authentication middleware. Calls the Guard to resolve an AuthContext from
 * the request. On success, wraps the scope in an AuthenticatedExecutionContext
 * (which also satisfies AuthenticatedRequestScope) and passes it to $next. On
 * failure, throws AuthenticationException which the runner converts to a 401.
 *
 * Implements both Middleware (preferred, typed $next closure) and Executable
 * (legacy chain compatibility). The Executable path reads the next handler
 * from the 'handler.next' scope attribute and constructs the closure bridge.
 */
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

    public function __invoke(ExecutionScope $scope): mixed
    {
        assert($scope instanceof RequestScope);

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
