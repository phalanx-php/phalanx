<?php

declare(strict_types=1);

namespace Acme\HttpDemo\Runtime\Routes;

use Phalanx\Http\RequestContext;
use Phalanx\Task\Scopeable;

final readonly class Health implements Scopeable
{
    /** @return array{demo: string, status: string} */
    public function __invoke(RequestContext $ctx): array
    {
        return ['demo' => 'runtime-lifecycle', 'status' => 'ready'];
    }
}
