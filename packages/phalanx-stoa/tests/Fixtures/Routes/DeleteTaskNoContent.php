<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Fixtures\Routes;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Stoa\Response\NoContent;
use Phalanx\Task\Executable;

final class DeleteTaskNoContent implements Executable
{
    public function __invoke(ExecutionScope $scope): NoContent
    {
        return new NoContent();
    }
}
