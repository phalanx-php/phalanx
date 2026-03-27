<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Phalanx\Auth\AuthContext;

interface AuthenticatedRequestScope extends RequestScope
{
    public AuthContext $auth { get; }
}
