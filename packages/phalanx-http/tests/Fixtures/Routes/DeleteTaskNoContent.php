<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Fixtures\Routes;

use Phalanx\ExecutionScope;
use Phalanx\Http\Response\NoContent;
use Phalanx\Task\Executable;

final class DeleteTaskNoContent implements Executable
{
    public function __invoke(ExecutionScope $scope): NoContent
    {
        return new NoContent();
    }
}
