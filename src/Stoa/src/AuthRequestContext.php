<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Phalanx\Auth\AuthContext;

interface AuthRequestContext extends RequestContext
{
    public AuthContext $auth { get; }
}
