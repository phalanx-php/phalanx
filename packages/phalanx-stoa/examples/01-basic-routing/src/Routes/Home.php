<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Basic\Routes;

use Phalanx\Stoa\RequestScope;
use Phalanx\Task\Scopeable;

final class Home implements Scopeable
{
    /** @return array{demo: string, method: string, path: string} */
    public function __invoke(RequestScope $scope): array
    {
        return [
            'demo' => 'basic-routing',
            'method' => $scope->method(),
            'path' => $scope->path(),
        ];
    }
}
