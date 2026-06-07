<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures\TestingPaths\Tests\Acceptance;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;

final class DirectTestAppApplicationViolation extends PhalanxTestCase
{
    public function directPropertyFetch(): void
    {
        $app = $this->testApp();

        $app->application->scoped(static fn(ExecutionScope $_scope): null => null);
    }

    public function chainedPropertyFetch(): void
    {
        $this->testApp()->application->startup();
    }
}
