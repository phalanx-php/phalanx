<?php

declare(strict_types=1);

namespace Acme\HttpDemo\Api\Routes;

use Phalanx\Http\RequestContext;
use Phalanx\Task\Scopeable;

final class ShowTask implements Scopeable
{
    /** @return array{task: array{id: int, title: string}} */
    public function __invoke(RequestContext $ctx): array
    {
        $id = (int) $ctx->params->get('id');

        return [
            'task' => [
                'id' => $id,
                'title' => "Task {$id}",
            ],
        ];
    }
}
