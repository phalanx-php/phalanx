<?php

declare(strict_types=1);

namespace Phalanx\Demos\Hydra\CancellationFailFast;

use Phalanx\Worker\WorkerScope;
use Phalanx\Worker\WorkerTask;

class WorkerHealthTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __construct(
        private readonly string $label,
    ) {
    }

    /** @return array{label: string, pid: int} */
    public function __invoke(WorkerScope $scope): array
    {
        $pid = getmypid();
        if ($pid === false) {
            throw new \RuntimeException('Could not read worker process id.');
        }

        return [
            'label' => $this->label,
            'pid'   => $pid,
        ];
    }
}
