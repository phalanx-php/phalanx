<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures\TestingPaths\Tests\Acceptance;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;

final class DirectTestAppApplicationValid extends PhalanxTestCase
{
    public function sanctionedTestAppApi(): void
    {
        $app = $this->testApp();

        $app->scoped(static fn(ExecutionScope $_scope): null => null);
        $app->start();
        $app->runtime();
        $app->supervisor();
        $app->ledger->assertNoOrphans();
    }
}
