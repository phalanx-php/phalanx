<?php

declare(strict_types=1);

namespace Phalanx\Tui\Core;

interface RefreshesPeriodically
{
    public function refreshIntervalSeconds(): ?float;
}
