<?php

declare(strict_types=1);

namespace Phalanx\Recovery;

use Phalanx\Mark\Mark;

enum RecoveryPreset
{
    case DefaultRetry;
    case FailFast;
    case Polling;
    case LongRunning;

    public function toPlan(): RecoveryPlan
    {
        return match ($this) {
            self::DefaultRetry => RecoveryPlan::defaultRetry(),
            self::FailFast => RecoveryPlan::failFast(),
            self::Polling => RecoveryPlan::polling(interval: Mark::ms(250)),
            self::LongRunning => RecoveryPlan::longRunning(),
        };
    }
}
