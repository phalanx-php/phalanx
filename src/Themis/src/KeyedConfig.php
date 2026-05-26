<?php

declare(strict_types=1);

namespace Phalanx\Themis;

use BackedEnum;

interface KeyedConfig extends Config
{
    public function key(): BackedEnum;
}
