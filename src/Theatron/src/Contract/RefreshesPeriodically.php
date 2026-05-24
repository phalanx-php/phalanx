<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Contract;

interface RefreshesPeriodically
{
    public function refreshIntervalSeconds(): ?float;
}
