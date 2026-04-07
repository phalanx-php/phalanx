<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Fixtures\Routes;

use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;

final class ShowUserById implements Executable
{
    /** @return array{id: mixed, params: mixed} */
    public function __invoke(ExecutionScope $scope): array
    {
        return [
            'id' => $scope->attribute('route.id'),
            'params' => $scope->attribute('route.params'),
        ];
    }
}
