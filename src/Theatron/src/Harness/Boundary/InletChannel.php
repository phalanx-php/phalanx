<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Harness\Boundary;

interface InletChannel
{
    public function emit(InletMessage $message): void;
}
