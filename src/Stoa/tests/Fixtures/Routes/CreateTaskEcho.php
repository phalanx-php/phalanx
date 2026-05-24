<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Tests\Fixtures\Routes;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Stoa\Response\Created;
use Phalanx\Task\Executable;
use Phalanx\Stoa\Tests\Fixtures\CreateTaskInput;

final class CreateTaskEcho implements Executable
{
    public function __invoke(ExecutionScope $scope, CreateTaskInput $input): Created
    {
        return new Created($input);
    }
}
