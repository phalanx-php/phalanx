<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures\TestingPaths\Tests\Acceptance;

use Phalanx\Application;

final class RuleSpecificExemptionStillReports
{
    public function bootWithRawSleep(): void
    {
        usleep(1000);

        $app = Application::starting()->compile();
    }
}
