<?php

declare(strict_types=1);

namespace Phalanx\Testing\Lenses;

use Phalanx\Diagnostics\DoctorReport;
use Phalanx\Diagnostics\EnvironmentDoctor;
use Phalanx\Testing\Attribute\Lens;
use Phalanx\Testing\Lens as LensContract;
use Phalanx\Testing\TestApp;
use PHPUnit\Framework\Assert;

/**
 * Environment health reporting backed by EnvironmentDoctor.
 *
 * Surfaces the same checks Aegis runs at boot — runtime hook policy state,
 * memory pressure on managed tables, dropped runtime events, listener
 * failures — but with a test-friendly assertion surface. Use to gate
 * integration tests on a clean OpenSwoole substrate before running work.
 */
#[Lens(
    accessor: 'runtime',
    returns: self::class,
    factory: RuntimeLensFactory::class,
    requires: [],
)]
final class RuntimeLens implements LensContract
{
    public function __construct(private readonly TestApp $app)
    {
    }

    public function report(): DoctorReport
    {
        return $this->doctor()->check();
    }

    /**
     * Assert every doctor check is green. Only meaningful inside an active
     * coroutine — runtime hook state is not engaged outside of scoped runs,
     * so this method should be called from within $app->application->scoped(...)
     * for it to reflect production health. For post-run teardown checks,
     * prefer assertResourcesClean().
     */
    public function assertHealthy(): self
    {
        $report = $this->report();

        if ($report->isHealthy()) {
            return $this;
        }

        $details = [];
        foreach ($report as $check) {
            if (!$check->ok) {
                $details[] = "{$check->name}: {$check->detail}";
            }
        }

        Assert::fail(
            'Runtime is not healthy. Failing checks: ' . implode('; ', $details),
        );
    }

    /**
     * Assert that runtime memory and resource tables are empty. Mirrors the
     * teardown contract Aegis enforces in PhalanxTestCase and is safe to call
     * outside a coroutine run.
     */
    public function assertResourcesClean(): self
    {
        $memory = $this->app->application->runtime()->memory;
        $live = $memory->resources->liveCount();

        Assert::assertSame(
            0,
            $live,
            "Expected no live runtime handles; {$live} still live.",
        );
        Assert::assertSame(
            0,
            $memory->tables->resources->count(),
            'Expected no retained runtime handles.',
        );
        Assert::assertSame(
            0,
            $memory->tables->resourceEdges->count(),
            'Expected no retained runtime relationships.',
        );
        Assert::assertSame(
            0,
            $memory->tables->resourceLeases->count(),
            'Expected no retained runtime leases.',
        );
        Assert::assertSame(
            0,
            $memory->tables->resourceAnnotations->count(),
            'Expected no retained runtime annotations.',
        );

        return $this;
    }

    /** @return array{taskRun: array{hits: int, misses: int, overflows: int, drops: int, borrowed: int, free: int, capacity: int}, token: array{hits: int, misses: int, free: int, capacity: int}} */
    public function poolStats(): array
    {
        return $this->app->application->supervisor()->poolStats();
    }

    public function assertPoolsClean(): self
    {
        $stats = $this->poolStats();
        $borrowed = $stats['taskRun']['borrowed'];

        Assert::assertSame(
            0,
            $borrowed,
            "Expected no borrowed supervisor task runs; {$borrowed} still borrowed.",
        );

        return $this;
    }

    public function assertNoBorrowedPools(): self
    {
        return $this->assertPoolsClean();
    }

    public function assertCheckFails(string $name): self
    {
        foreach ($this->report() as $check) {
            if ($check->name !== $name) {
                continue;
            }

            Assert::assertFalse(
                $check->ok,
                "Expected runtime check '{$name}' to be failing; it reported ok with detail: {$check->detail}.",
            );

            return $this;
        }

        Assert::fail("Runtime check '{$name}' was not found in the report.");
    }

    public function reset(): void
    {
    }

    private function doctor(): EnvironmentDoctor
    {
        return new EnvironmentDoctor(
            ledger: $this->app->application->supervisor()->ledger,
            memory: $this->app->application->runtime()->memory,
            supervisor: $this->app->application->supervisor(),
        );
    }
}
