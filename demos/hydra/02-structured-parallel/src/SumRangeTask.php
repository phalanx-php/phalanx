<?php

declare(strict_types=1);

namespace Phalanx\Demos\Hydra\StructuredParallel;

use Phalanx\Worker\WorkerScope;
use Phalanx\Worker\WorkerTask;

class SumRangeTask implements WorkerTask
{
    public string $traceName {
        get => self::class;
    }

    public function __construct(
        private readonly int $from,
        private readonly int $to,
    ) {
    }

    /** @return array{from: int, to: int, sum: int, pid: int} */
    public function __invoke(WorkerScope $scope): array
    {
        $pid = getmypid();
        if ($pid === false) {
            throw new \RuntimeException('Could not read worker process id.');
        }

        return [
            'from' => $this->from,
            'to'   => $this->to,
            'sum'  => array_sum(range($this->from, $this->to)),
            'pid'  => $pid,
        ];
    }
}
