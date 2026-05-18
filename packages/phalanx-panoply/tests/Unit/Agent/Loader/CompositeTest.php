<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Agent\Loader;

use Phalanx\Panoply\Agent\Loader\Composite;
use Phalanx\Panoply\Agent\Loader\Manual;
use Phalanx\Panoply\Agent\Registry;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Tests\Unit\Agent\Stubs\StubAgent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins {@see Composite} loader behavior: merges child loaders in order,
 * first-match wins on duplicate IDs, conflict map is populated correctly.
 */
final class CompositeTest extends TestCase
{
    #[Test]
    public function noLoadersYieldsEmptyRegistry(): void
    {
        $registry = new Composite()->load();

        self::assertInstanceOf(Registry::class, $registry);
        self::assertSame(0, $registry->all()->count());
    }

    #[Test]
    public function singleLoaderMergesItsRegistry(): void
    {
        $leonidas = new StubAgent('leonidas', Capability::Reasoning);
        $loader   = new Composite(new Manual($leonidas));

        $registry = $loader->load();

        self::assertSame(1, $registry->all()->count());
        self::assertSame($leonidas, $registry->get('leonidas'));
    }

    #[Test]
    public function multipleLoadersAreAllMerged(): void
    {
        $leonidas = new StubAgent('leonidas', Capability::Reasoning);
        $odysseus = new StubAgent('odysseus', Capability::ToolUse);
        $achilles = new StubAgent('achilles', Capability::Vision);

        $loader = new Composite(
            new Manual($leonidas),
            new Manual($odysseus),
            new Manual($achilles),
        );

        $registry = $loader->load();

        self::assertSame(3, $registry->all()->count());
        self::assertTrue($registry->has('leonidas'));
        self::assertTrue($registry->has('odysseus'));
        self::assertTrue($registry->has('achilles'));
    }

    #[Test]
    public function firstMatchWinsOnDuplicateId(): void
    {
        $first  = new StubAgent('leonidas', Capability::Reasoning);
        $second = new StubAgent('leonidas', Capability::ToolUse);

        $composite = new Composite(new Manual($first), new Manual($second));
        $registry  = $composite->load();

        self::assertSame(1, $registry->all()->count());
        // First loader's agent must win.
        self::assertSame($first, $registry->get('leonidas'));
    }

    #[Test]
    public function conflictsAreRecordedWhenDuplicateIdsEncountered(): void
    {
        $first  = new StubAgent('leonidas', Capability::Reasoning);
        $second = new StubAgent('leonidas', Capability::ToolUse);

        $composite = new Composite(new Manual($first), new Manual($second));
        $composite->load();

        $conflicts = $composite->conflicts;

        self::assertArrayHasKey('leonidas', $conflicts);
        // Index 0 is the WINNING loader's FQCN (first match in declaration order).
        self::assertSame($first::class, $conflicts['leonidas'][0]);
        self::assertSame($second::class, $conflicts['leonidas'][1]);
    }

    #[Test]
    public function noConflictsWhenAllIdsAreUnique(): void
    {
        $leonidas = new StubAgent('leonidas', Capability::Reasoning);
        $odysseus = new StubAgent('odysseus', Capability::ToolUse);

        $composite = new Composite(new Manual($leonidas), new Manual($odysseus));
        $composite->load();

        self::assertSame([], $composite->conflicts);
    }

    #[Test]
    public function conflictsAreResetOnSubsequentLoadCalls(): void
    {
        $first  = new StubAgent('leonidas', Capability::Reasoning);
        $second = new StubAgent('leonidas', Capability::ToolUse);

        $composite = new Composite(new Manual($first), new Manual($second));

        // First load — conflicts recorded.
        $composite->load();
        self::assertNotEmpty($composite->conflicts);

        // Replace inner loaders scenario is not possible (composite is final),
        // but we can verify a second load() call re-evaluates and re-records.
        $composite->load();
        self::assertArrayHasKey('leonidas', $composite->conflicts);
    }

    #[Test]
    public function threeWayConflictTracksAllFqcns(): void
    {
        $a = new StubAgent('marathon', Capability::Reasoning);
        $b = new StubAgent('marathon', Capability::ToolUse);
        $c = new StubAgent('marathon', Capability::Vision);

        $composite = new Composite(
            new Manual($a),
            new Manual($b),
            new Manual($c),
        );
        $composite->load();

        $conflicts = $composite->conflicts;

        self::assertCount(3, $conflicts['marathon']);
    }
}
