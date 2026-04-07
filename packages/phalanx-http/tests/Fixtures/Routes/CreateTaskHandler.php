<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Fixtures\Routes;

use Phalanx\ExecutionScope;
use Phalanx\Http\Response\Created;
use Phalanx\Task\Executable;
use Phalanx\Tests\Http\Fixtures\CreateTaskInput;
use Phalanx\Tests\Http\Fixtures\TaskResource;
use Phalanx\Tests\Http\Fixtures\TaskStatus;

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
