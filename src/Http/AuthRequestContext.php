<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Phalanx\Auth\AuthContext;

interface AuthRequestContext extends RequestContext
{
    public AuthContext $auth { get; }
}
