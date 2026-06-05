<?php

declare(strict_types=1);

namespace Phalanx\Tui\Core;

interface HasActivityPulse
{
    public function activityIsBusy(): bool;

    public function tickActivity(): void;
}
