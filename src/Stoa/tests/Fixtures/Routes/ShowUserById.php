<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Tests\Fixtures\Routes;

use Phalanx\Stoa\RequestContext;
use Phalanx\Task\Executable;

final class ShowUserById implements Executable
{
    /** @return array{id: ?string, params: array<string, string>} */
    public function __invoke(RequestContext $ctx): array
    {
        return [
            'id' => $ctx->params->get('id'),
            'params' => $ctx->params->all(),
        ];
    }
}
