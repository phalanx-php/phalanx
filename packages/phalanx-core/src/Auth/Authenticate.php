<?php

declare(strict_types=1);

namespace Phalanx\Auth;

use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use Psr\Http\Message\ServerRequestInterface;

final class Authenticate implements Executable
{
    public function __construct(
        private readonly Guard $guard,
    ) {
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        $request = $scope->attribute('request');

        if (!$request instanceof ServerRequestInterface) {
            throw new AuthenticationException('No request available for authentication');
        }

        $auth = $this->guard->resolve($request);

        if ($auth === null) {
            throw new AuthenticationException();
        }

        $scope = $scope->withAttribute('auth', $auth);

        /** @var Executable $next */
        $next = $scope->attribute('handler.next');

        return $scope->execute($next);
    }
}
