<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Phalanx\Application;

/**
 * Fixture NOT inside a test integration directory — the rule should ignore
 * direct ::starting() calls here.
 */
final class UseTestAppOutsideIntegrationDir
{
    public function bootAppForProductionUse(): void
    {
        Application::starting()->compile();
    }
}
