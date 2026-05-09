<?php

declare(strict_types=1);

namespace Phalanx\Archon\Testing;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Testing\TestLens;

/**
 * Marker bundle that activates Archon's ConsoleLens on a TestApp.
 *
 * Adoption pattern in tests:
 *
 *     $app = $this->testApp($context, new ArchonTestableBundle());
 *
 *     $app->console
 *         ->commands(CommandGroup::of(['greet' => [GreetCommand::class, ...]]))
 *         ->run(['greet', 'Ada'])
 *         ->assertSuccessful();
 *
 * The bundle registers no services itself — its sole job is to declare
 * ConsoleLens to TestApp's lens registry. The lens builds its own
 * ArchonApplication internally on each run().
 */
class ArchonTestableBundle extends ServiceBundle
{
    #[\Override]
    public static function lens(): TestLens
    {
        return TestLens::of(ConsoleLens::class);
    }

    public function services(Services $services, AppContext $context): void
    {
    }
}
