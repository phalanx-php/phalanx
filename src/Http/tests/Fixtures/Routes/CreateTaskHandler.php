<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Fixtures\Routes;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Http\Response\Created;
use Phalanx\Task\Executable;
use Phalanx\Http\Tests\Fixtures\CreateTaskInput;
use Phalanx\Http\Tests\Fixtures\TaskResource;
use Phalanx\Http\Tests\Fixtures\TaskStatus;

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
