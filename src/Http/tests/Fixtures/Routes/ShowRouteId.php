<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Fixtures\Routes;

use Phalanx\Http\RequestContext;
use Phalanx\Task\Executable;

final class ShowRouteId implements Executable
{
    public function __invoke(RequestContext $ctx): mixed
    {
        return $ctx->params->get('id');
    }
}
