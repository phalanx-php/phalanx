<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Core;

use Phalanx\Theatron\Tui\Core\EffectContext;

interface Effect
{
    public function __invoke(EffectContext $ctx): mixed;
}
