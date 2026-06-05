<?php

declare(strict_types=1);

namespace Acme\HttpDemo\Realtime\Routes;

use Phalanx\Http\RequestContext;
use Phalanx\Task\Scopeable;

final class Health implements Scopeable
{
    /** @return array{status: string, demo: string} */
    public function __invoke(RequestContext $ctx): array
    {
        return ['status' => 'ok', 'demo' => 'realtime'];
    }
}
