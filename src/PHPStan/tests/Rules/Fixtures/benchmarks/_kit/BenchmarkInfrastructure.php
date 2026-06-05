<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures\Benchmarks\Kit;

use Phalanx\Application;

final class BenchmarkInfrastructure
{
    public function bareApplication(): void
    {
        Application::starting()->compile();
    }
}
