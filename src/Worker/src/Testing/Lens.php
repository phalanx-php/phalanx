<?php

declare(strict_types=1);

namespace Phalanx\Worker\Testing;

use Phalanx\Runtime\Identity\RuntimeResourceSid;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use Phalanx\Testing\Attribute\Lens as LensAttribute;
use Phalanx\Testing\Lens as LensContract;
use Phalanx\Testing\TestApp;
use Phalanx\Worker\WorkerTask;

#[LensAttribute(
    accessor: 'worker',
    returns: self::class,
    factory: LensFactory::class,
    requires: [],
)]
final class Lens implements LensContract
{
    public function __construct(private readonly TestApp $app)
    {
    }

    public function run(WorkerTask $task): Result
    {
        $value = $this->app->application->scoped(Task::named(
            'worker.testing.dispatch',
            static fn(ExecutionScope $scope): mixed => $scope->inWorker($task),
        ));
        $application = $this->app->application;

        return new Result(
            value: $value,
            liveTasks: $application->supervisor()->liveCount(),
            liveRuntimeScopes: $application->runtime()->memory->resources->liveCount(RuntimeResourceSid::Scope),
        );
    }

    public function reset(): void
    {
    }
}
