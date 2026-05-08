<?php

declare(strict_types=1);

namespace Phalanx\Archon\Testing;

use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Testing\TestableBundle;
use Phalanx\Testing\Lens;

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
final class ArchonTestableBundle implements ServiceBundle, TestableBundle
{
    /** @return list<class-string<Lens>> */
    public static function testLenses(): array
    {
        return [ConsoleLens::class];
    }

    public function services(Services $services, array $context): void
    {
    }
}
