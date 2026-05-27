<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Tests\Fixtures\Routes;

use Phalanx\Stoa\RequestContext;
use Phalanx\Task\Executable;

final class ShowRouteId implements Executable
{
    public function __invoke(RequestContext $ctx): mixed
    {
        return $ctx->params->get('id');
    }
}
