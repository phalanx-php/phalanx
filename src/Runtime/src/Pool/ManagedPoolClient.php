<?php

declare(strict_types=1);

namespace Phalanx\Pool;

interface ManagedPoolClient
{
    public function close(): void;
}
