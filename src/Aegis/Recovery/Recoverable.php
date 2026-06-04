<?php

declare(strict_types=1);

namespace Phalanx\Recovery;

interface Recoverable
{
    public RecoveryPlan $recovery { get; }
}
