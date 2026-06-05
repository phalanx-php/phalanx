<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use Phalanx\Auth\AuthContext;

interface AuthenticatedContext extends \Phalanx\WebSocket\Context
{
    public AuthContext $auth { get; }
}
