<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures\TestingPaths\Tests\Acceptance;

use Phalanx\Testing\PhalanxTestCase;

final class DirectTestAppApplicationExemptionStillReports extends PhalanxTestCase
{
    public function directApplicationAndDirectScope(): void
    {
        $app = $this->testApp();

        $scope = $app->application->createScope();
        $scope->dispose();
    }
}
