<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Core;

interface RefreshesPeriodically
{
    public function refreshIntervalSeconds(): ?float;
}
