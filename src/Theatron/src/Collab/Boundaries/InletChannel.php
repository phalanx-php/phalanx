<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Boundaries;

interface InletChannel
{
    public function emit(InletMessage $message): void;
}
