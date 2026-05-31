<?php

declare(strict_types=1);

namespace Phalanx\Harness\Boundary;

interface InletChannel
{
    public function emit(InletMessage $message): void;
}
