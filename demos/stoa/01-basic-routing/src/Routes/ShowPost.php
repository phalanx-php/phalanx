<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Basic\Routes;

use Phalanx\Stoa\RequestScope;
use Phalanx\Task\Scopeable;

final class ShowPost implements Scopeable
{
    /** @return array{post: array{slug: string}, route: array{method: string, path: string}} */
    public function __invoke(RequestScope $scope): array
    {
        return [
            'post' => [
                'slug' => (string) $scope->params->get('slug'),
            ],
            'route' => [
                'method' => $scope->method(),
                'path' => $scope->path(),
            ],
        ];
    }
}
