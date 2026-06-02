<?php

declare(strict_types=1);

namespace Phalanx\Hermes;

use Phalanx\Auth\AuthContext;

interface AuthWsContext extends WsContext
{
    public AuthContext $auth { get; }
}
