<?php

declare(strict_types=1);

namespace Phalanx\Scheduling;

interface SchedulePolicy
{
    public function configure(ScheduleBuilder $schedule): ScheduleBuilder;
}
