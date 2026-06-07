<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Phalanx\Lifecycle\LifecycleCallbacks;
use Phalanx\Lifecycle\LifecyclePhase;

final class GenericLifecycleFixture
{
    public function generic(LifecycleCallbacks $callbacks): LifecyclePhase
    {
        return LifecyclePhase::Ready;
    }
}
