<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Basic\Routes;

use Phalanx\Stoa\RequestContext;
use Phalanx\Task\Scopeable;

final class ShowUser implements Scopeable
{
    /** @return array{user: array{id: int}, route: array{method: string, path: string}} */
    public function __invoke(RequestContext $ctx): array
    {
        return [
            'user' => [
                'id' => (int) $ctx->params->get('id'),
            ],
            'route' => [
                'method' => $ctx->method(),
                'path' => $ctx->path(),
            ],
        ];
    }
}
