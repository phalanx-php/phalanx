<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Basic\Routes;

use Phalanx\Stoa\RequestContext;
use Phalanx\Task\Scopeable;

final class ShowPost implements Scopeable
{
    /** @return array{post: array{slug: string}, route: array{method: string, path: string}} */
    public function __invoke(RequestContext $ctx): array
    {
        return [
            'post' => [
                'slug' => (string) $ctx->params->get('slug'),
            ],
            'route' => [
                'method' => $ctx->method(),
                'path' => $ctx->path(),
            ],
        ];
    }
}
