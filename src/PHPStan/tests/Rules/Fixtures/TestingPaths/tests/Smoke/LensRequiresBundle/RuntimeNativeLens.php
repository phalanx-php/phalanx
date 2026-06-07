<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures\TestingPaths\Tests\Smoke\LensRequiresBundle;

use Phalanx\Testing\PhalanxTestCase;

final class RuntimeNativeLens extends PhalanxTestCase
{
    public function runtimeNativeLensesAreAvailable(): void
    {
        $app = $this->testApp();

        $ledger = $app->ledger;
        $scope = $app->scope;
        $runtime = $app->runtime;
        $config = $app->config;
    }
}
