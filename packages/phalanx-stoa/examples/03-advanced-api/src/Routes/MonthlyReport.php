<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Advanced\Routes;

use Phalanx\Stoa\RequestScope;
use Phalanx\Task\Scopeable;

final class MonthlyReport implements Scopeable
{
    /** @return array{period: array{year: int, month: int}, total: int} */
    public function __invoke(RequestScope $scope): array
    {
        return [
            'period' => [
                'year' => (int) $scope->params->get('year'),
                'month' => (int) $scope->params->get('month'),
            ],
            'total' => 128,
        ];
    }
}
