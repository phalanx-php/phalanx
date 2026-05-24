<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Phalanx\Auth\AuthContext;

interface AuthRequestScope extends RequestScope
{
    public AuthContext $auth { get; }
}
