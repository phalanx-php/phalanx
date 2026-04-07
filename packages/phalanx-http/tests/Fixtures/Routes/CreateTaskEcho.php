<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Fixtures\Routes;

use Phalanx\ExecutionScope;
use Phalanx\Http\Response\Created;
use Phalanx\Task\Executable;
use Phalanx\Tests\Http\Fixtures\CreateTaskInput;

final class CreateTaskEcho implements Executable
{
    public function __invoke(ExecutionScope $scope, CreateTaskInput $input): Created
    {
        return new Created($input);
    }
}
