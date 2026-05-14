<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Fixtures\Routes;

use Phalanx\Stoa\RequestScope;
use Phalanx\Task\Executable;

final class ShowUserById implements Executable
{
    /** @return array{id: ?string, params: array<string, string>} */
    public function __invoke(RequestScope $scope): array
    {
        return [
            'id' => $scope->params->get('id'),
            'params' => $scope->params->all(),
        ];
    }
}
