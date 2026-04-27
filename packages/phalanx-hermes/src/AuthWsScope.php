<?php

declare(strict_types=1);

namespace Phalanx\Hermes;

use Phalanx\Auth\AuthContext;

interface AuthWsScope extends WsScope
{
    public AuthContext $auth { get; }
}
