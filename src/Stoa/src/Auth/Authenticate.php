<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Auth;

use Closure;
use Phalanx\Auth\AuthenticationException;
use Phalanx\Auth\Guard;
use Phalanx\Stoa\AuthExecutionContext;
use Phalanx\Stoa\Contract\Middleware;
use Phalanx\Stoa\RequestContext;

final class Authenticate implements Middleware
{
    public function __construct(private readonly Guard $guard)
    {
    }

    public function __invoke(RequestContext $ctx, Closure $next): mixed
    {
        $auth = $this->guard->authenticate($ctx->request);

        if ($auth === null) {
            throw new AuthenticationException();
        }

        return $next(new AuthExecutionContext($ctx, $auth));
    }
}
