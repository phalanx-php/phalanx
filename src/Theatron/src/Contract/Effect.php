<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Contract;

use Phalanx\Theatron\Context\EffectContext;

interface Effect
{
    public function __invoke(EffectContext $ctx): mixed;
}
