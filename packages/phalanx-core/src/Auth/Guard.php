<?php

declare(strict_types=1);

namespace Phalanx\Auth;

use Psr\Http\Message\ServerRequestInterface;

interface Guard
{
    public function authenticate(ServerRequestInterface $request): ?AuthContext;
}
