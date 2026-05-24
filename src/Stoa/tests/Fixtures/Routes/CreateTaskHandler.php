<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Tests\Fixtures\Routes;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Stoa\Response\Created;
use Phalanx\Task\Executable;
use Phalanx\Stoa\Tests\Fixtures\CreateTaskInput;
use Phalanx\Stoa\Tests\Fixtures\TaskResource;
use Phalanx\Stoa\Tests\Fixtures\TaskStatus;

final class CreateTaskHandler implements Executable
{
    public function __invoke(ExecutionScope $scope, CreateTaskInput $input): Created
    {
        return new Created(new TaskResource(
            id: 1,
            title: $input->title,
            description: $input->description,
            priority: $input->priority,
            status: TaskStatus::Pending,
        ));
    }
}
