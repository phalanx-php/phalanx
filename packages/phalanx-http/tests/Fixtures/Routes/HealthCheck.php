<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Fixtures\Routes;

use Phalanx\Scope;
use Phalanx\Task\Scopeable;

final class HealthCheck implements Scopeable
{
    /** @return array{status: string} */
    public function __invoke(Scope $scope): array
    {
        return ['status' => 'ok'];
    }
}
