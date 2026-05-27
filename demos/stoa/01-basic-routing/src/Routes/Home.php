<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Basic\Routes;

use Phalanx\Stoa\RequestContext;
use Phalanx\Task\Scopeable;

final class Home implements Scopeable
{
    /** @return array{demo: string, method: string, path: string} */
    public function __invoke(RequestContext $ctx): array
    {
        return [
            'demo' => 'basic-routing',
            'method' => $ctx->method(),
            'path' => $ctx->path(),
        ];
    }
}
