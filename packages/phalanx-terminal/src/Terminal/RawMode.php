<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Terminal;

interface RawMode
{
    public function enable(): void;
    public function disable(): void;
}
