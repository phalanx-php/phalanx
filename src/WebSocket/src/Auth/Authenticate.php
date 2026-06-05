<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Auth;

use Closure;
use Phalanx\Auth\AuthenticationException;
use Phalanx\Auth\Guard;
use Phalanx\Task\Executable;
use Phalanx\WebSocket\AuthExecutionContext;

final readonly class Authenticate implements Executable
{
    public function __construct(
        private Guard $guard,
    ) {
    }

    /** @param Closure(\Phalanx\WebSocket\Context):mixed $next */
    public function __invoke(\Phalanx\WebSocket\Context $ctx, Closure $next): mixed
    {
        $auth = $this->guard->authenticate($ctx->request);

        if ($auth === null) {
            throw new AuthenticationException();
        }

        $authenticated = new AuthExecutionContext($ctx, $auth);

        return $next($authenticated);
    }
}
