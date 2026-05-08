<?php

declare(strict_types=1);

namespace Phalanx\Testing\Lenses;

use Phalanx\Supervisor\Supervisor;
use Phalanx\Supervisor\TaskTreeFormatter;
use Phalanx\Testing\Attribute\TestLens;
use Phalanx\Testing\TestApp;
use Phalanx\Testing\TestLens as TestLensContract;
use PHPUnit\Framework\Assert;

/**
 * Ledger inspection and assertions for the active Application's supervisor.
 *
 * Exposes the live task tree as the runtime sees it. Userland tests reach
 * for this lens to assert that no orphan tasks survived a run, that a named
 * task completed, or to dump the tree into a failure message for diagnosis.
 *
 * The lens reads from Supervisor's pass-through to LedgerStorage; it never
 * mutates the ledger.
 */
#[TestLens(
    accessor: 'ledger',
    returns: self::class,
    factory: LedgerLensFactory::class,
    requires: [],
)]
final class LedgerLens implements TestLensContract
{
    public function __construct(private readonly TestApp $app)
    {
    }

    public function liveTaskCount(): int
    {
        return $this->supervisor()->liveCount();
    }

    public function liveScopeCount(): int
    {
        return $this->supervisor()->liveScopeCount();
    }

    public function tree(): string
    {
        return new TaskTreeFormatter()->format($this->supervisor()->tree());
    }

    public function assertNoOrphans(): self
    {
        $live = $this->supervisor()->liveCount();

        Assert::assertSame(
            0,
            $live,
            "Expected no live tasks; ledger reports {$live}.\n" . $this->tree(),
        );

        return $this;
    }

    public function assertNoLiveScopes(): self
    {
        $live = $this->supervisor()->liveScopeCount();

        Assert::assertSame(
            0,
            $live,
            "Expected no live scopes; ledger reports {$live}.",
        );

        return $this;
    }

    public function assertTreeContains(string $needle): self
    {
        Assert::assertStringContainsString($needle, $this->tree());

        return $this;
    }

    public function reset(): void
    {
    }

    private function supervisor(): Supervisor
    {
        return $this->app->application->supervisor();
    }
}
