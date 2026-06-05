<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Phalanx\Application;

final class UseBenchmarkHarnessOutsideBenchmarkDir
{
    public function bootAppOutsideBenchmarkDirectory(): void
    {
        Application::starting()->compile();
    }
}
