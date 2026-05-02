<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Advanced\Security;

use Phalanx\Auth\AuthContext;
use Phalanx\Auth\Guard;
use Psr\Http\Message\ServerRequestInterface;

final class DemoGuard implements Guard
{
    public function authenticate(ServerRequestInterface $request): ?AuthContext
    {
        $header = $request->getHeaderLine('Authorization');

        if ($header !== 'Bearer demo-token') {
            return null;
        }

        return AuthContext::authenticated(
            new DemoIdentity('demo-user'),
            'demo-token',
            ['jobs:create'],
        );
    }
}
