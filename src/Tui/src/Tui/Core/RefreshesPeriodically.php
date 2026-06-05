<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Core;

interface RefreshesPeriodically
{
    public function refreshIntervalSeconds(): ?float;
}
