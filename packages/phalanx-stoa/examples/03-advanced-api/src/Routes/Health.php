<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Advanced\Routes;

use Phalanx\Stoa\RequestScope;
use Phalanx\Task\Scopeable;

final class Health implements Scopeable
{
    /** @return array{status: string, path: string} */
    public function __invoke(RequestScope $scope): array
    {
        return [
            'status' => 'ok',
            'path' => $scope->path(),
        ];
    }
}
