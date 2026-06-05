<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Core;

use Phalanx\Tui\Tui\Core\EffectContext;

interface Effect
{
    public function __invoke(EffectContext $ctx): mixed;
}
