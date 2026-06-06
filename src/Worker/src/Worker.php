<?php

declare(strict_types=1);

namespace Phalanx\Worker;

use Phalanx\Service\ServiceBundle;

final class Worker
{
    private function __construct()
    {
    }

    public static function workers(?ParallelConfig $config = null): WorkerDispatch
    {
        return ($config ?? ParallelConfig::default())->workerDispatch();
    }

    public static function services(?ParallelConfig $config = null): ServiceBundle
    {
        return new Bundle($config);
    }
}
