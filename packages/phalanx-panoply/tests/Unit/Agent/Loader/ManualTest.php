<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Agent\Loader;

use Phalanx\Panoply\Agent\Loader\Manual;
use Phalanx\Panoply\Agent\Registry;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Tests\Unit\Agent\Stubs\StubAgent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins {@see Manual} loader behavior: explicit agent list yields an
 * immutable Registry containing exactly those agents.
 */
final class ManualTest extends TestCase
{
    #[Test]
    public function emptyConstructorYieldsEmptyRegistry(): void
    {
        $registry = new Manual()->load();

        self::assertInstanceOf(Registry::class, $registry);
        self::assertSame(0, $registry->all()->count());
    }

    #[Test]
    public function singleAgentIsRegistered(): void
    {
        $agent = new StubAgent('leonidas', Capability::Reasoning);
        $loader = new Manual($agent);

        $registry = $loader->load();

        self::assertTrue($registry->has('leonidas'));
        self::assertSame($agent, $registry->get('leonidas'));
    }

    #[Test]
    public function multipleAgentsAreAllRegistered(): void
    {
        $leonidas = new StubAgent('leonidas', Capability::Reasoning);
        $odysseus = new StubAgent('odysseus', Capability::ToolUse);
        $achilles = new StubAgent('achilles', Capability::Vision);

        $registry = new Manual($leonidas, $odysseus, $achilles)->load();

        self::assertSame(3, $registry->all()->count());
        self::assertTrue($registry->has('leonidas'));
        self::assertTrue($registry->has('odysseus'));
        self::assertTrue($registry->has('achilles'));
    }

    #[Test]
    public function laterAgentOverwritesEarlierDuplicateId(): void
    {
        // Registry::with() replaces on duplicate — last one wins.
        $first = new StubAgent('leonidas', Capability::Reasoning);
        $second = new StubAgent('leonidas', Capability::ToolUse);

        $registry = new Manual($first, $second)->load();

        self::assertSame(1, $registry->all()->count());
        self::assertSame($second, $registry->get('leonidas'));
    }

    #[Test]
    public function loadIsIdempotent(): void
    {
        $agent = new StubAgent('sparta', Capability::Reasoning);
        $loader = new Manual($agent);

        $r1 = $loader->load();
        $r2 = $loader->load();

        self::assertSame(1, $r1->all()->count());
        self::assertSame(1, $r2->all()->count());
    }
}
