<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Contracts\Routes;

use Phalanx\Stoa\RequestScope;
use Phalanx\Task\Scopeable;

final class ShowTask implements Scopeable
{
    /** @return array{task: array{id: int, title: string}} */
    public function __invoke(RequestScope $scope): array
    {
        $id = (int) $scope->params->get('id');

        return [
            'task' => [
                'id' => $id,
                'title' => "Task {$id}",
            ],
        ];
    }
}
