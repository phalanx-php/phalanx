<?php

declare(strict_types=1);

namespace Phalanx\Tui\Core;

use Phalanx\Tui\Core\EffectContext;

interface Effect
{
    public function __invoke(EffectContext $ctx): mixed;
}
