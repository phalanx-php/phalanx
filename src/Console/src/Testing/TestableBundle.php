<?php

declare(strict_types=1);

namespace Phalanx\Console\Testing;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Testing\TestLens;

/**
 * Marker bundle that activates Console's Lens on a TestApp.
 *
 * Adoption pattern in tests:
 *
 *     $app = $this->testApp($context, new TestableBundle());
 *
 *     $app->console
 *         ->commands(CommandGroup::of(['greet' => GreetCommand::class]))
 *         ->run(['greet', 'Ada'])
 *         ->assertSuccessful();
 *
 * The bundle registers no services itself — its sole job is to declare
 * Lens to TestApp's lens registry. The lens builds its own
 * Application internally on each run().
 */
class TestableBundle extends ServiceBundle
{
    #[\Override]
    public static function lens(): TestLens
    {
        return TestLens::of(Lens::class);
    }

    public function services(Services $services, AppContext $context): void
    {
    }
}
