<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

final class GenericLifecycleFixture
{
    public function generic(): string
    {
        return \Phalanx\Lifecycle\LifecyclePhase::Ready->value;
    }
}
