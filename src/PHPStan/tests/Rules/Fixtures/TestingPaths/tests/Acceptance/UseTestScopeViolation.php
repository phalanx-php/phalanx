<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures\TestingPaths\Tests\Acceptance;

use Phalanx\Application;
use Phalanx\Testing\PhalanxTestCase;

final class UseTestScopeViolation extends PhalanxTestCase
{
    public function testAppApplicationScope(): void
    {
        $app = $this->testApp();

        $scope = $app->application->createScope();
    }

    public function compiledApplicationScope(): void
    {
        $app = Application::starting()->compile();

        $scope = $app->createScope();
    }
}
