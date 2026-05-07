<?php

declare(strict_types=1);

namespace Phalanx\Hydra;

use Phalanx\Worker\WorkerDispatch;

class Hydra
{
    private function __construct()
    {
    }

    public static function workers(?ParallelConfig $config = null): WorkerDispatch
    {
        return ($config ?? ParallelConfig::default())->workerDispatch();
    }
}
