<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Runtime\Routes;

use Phalanx\Stoa\RequestScope;
use Phalanx\Task\Scopeable;

final readonly class Health implements Scopeable
{
    /** @return array{demo: string, status: string} */
    public function __invoke(RequestScope $scope): array
    {
        return ['demo' => 'runtime-lifecycle', 'status' => 'ready'];
    }
}
