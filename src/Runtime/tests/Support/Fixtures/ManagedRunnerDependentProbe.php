<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Support\Fixtures;

final class ManagedRunnerDependentProbe
{
    public function __construct(
        public readonly ManagedRunnerDependencyProbe $dependency,
    ) {
    }
}
