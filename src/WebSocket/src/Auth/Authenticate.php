<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Auth;

use Closure;
use Phalanx\Auth\AuthenticationException;
use Phalanx\Auth\Guard;
use Phalanx\Task\Executable;
use Phalanx\WebSocket\AuthExecutionContext;
use Phalanx\WebSocket\WsContext;

final class Authenticate implements Executable
{
    public function __construct(
        private readonly Guard $guard,
    ) {
    }

    /** @param Closure(WsContext): mixed $next */
    public function __invoke(WsContext $ctx, Closure $next): mixed
    {
        $auth = $this->guard->authenticate($ctx->request);

        if ($auth === null) {
            throw new AuthenticationException();
        }

        $authenticated = new AuthExecutionContext($ctx, $auth);

        return $next($authenticated);
    }
}
