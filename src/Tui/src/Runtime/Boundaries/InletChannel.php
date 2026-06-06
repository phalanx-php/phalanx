<?php

declare(strict_types=1);

namespace Phalanx\Tui\Runtime\Boundaries;

interface InletChannel
{
    public function emit(InletMessage $message): void;
}
