<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Actor;

use Phalanx\Stoa\RouteGroup;

interface ExposesRoutes
{
    public function routes(): RouteGroup;
}
