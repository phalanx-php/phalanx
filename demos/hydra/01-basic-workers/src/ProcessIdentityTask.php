<?php

declare(strict_types=1);

namespace Phalanx\Demos\Hydra\BasicWorkers;

use Phalanx\Worker\WorkerScope;
use Phalanx\Worker\WorkerTask;

class ProcessIdentityTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __construct(
        private readonly int $parentPid,
    ) {
    }

    /** @return array{parentPid: int, workerPid: int} */
    public function __invoke(WorkerScope $scope): array
    {
        $workerPid = getmypid();
        if ($workerPid === false) {
            throw new \RuntimeException('Could not read worker process id.');
        }

        return [
            'parentPid' => $this->parentPid,
            'workerPid' => $workerPid,
        ];
    }
}
