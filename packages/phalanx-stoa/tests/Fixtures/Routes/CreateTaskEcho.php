<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Fixtures\Routes;

use Phalanx\ExecutionScope;
use Phalanx\Stoa\Response\Created;
use Phalanx\Task\Executable;
use Phalanx\Tests\Stoa\Fixtures\CreateTaskInput;

final class CreateTaskEcho implements Executable
{
    public function __invoke(ExecutionScope $scope, CreateTaskInput $input): Created
    {
        return new Created($input);
    }
}
