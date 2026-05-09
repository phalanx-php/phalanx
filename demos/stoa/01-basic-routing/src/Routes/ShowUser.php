<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Basic\Routes;

use Phalanx\Stoa\RequestScope;
use Phalanx\Task\Scopeable;

final class ShowUser implements Scopeable
{
    /** @return array{user: array{id: int}, route: array{method: string, path: string}} */
    public function __invoke(RequestScope $scope): array
    {
        return [
            'user' => [
                'id' => (int) $scope->params->get('id'),
            ],
            'route' => [
                'method' => $scope->method(),
                'path' => $scope->path(),
            ],
        ];
    }
}
