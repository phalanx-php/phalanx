<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Realtime\Routes;

use Phalanx\Stoa\RequestScope;
use Phalanx\Task\Scopeable;

final class Health implements Scopeable
{
    /** @return array{status: string, demo: string} */
    public function __invoke(RequestScope $scope): array
    {
        return ['status' => 'ok', 'demo' => 'realtime'];
    }
}
