<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Api\Security;

use Phalanx\Auth\AuthContext;
use Phalanx\Auth\Guard;
use Psr\Http\Message\ServerRequestInterface;

final class DemoGuard implements Guard
{
    public function authenticate(ServerRequestInterface $request): ?AuthContext
    {
        if ($request->getHeaderLine('Authorization') !== 'Bearer demo-token') {
            return null;
        }

        return AuthContext::authenticated(
            new DemoIdentity('demo-user'),
            'demo-token',
            ['tasks:write'],
        );
    }
}
