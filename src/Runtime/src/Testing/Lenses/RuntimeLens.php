<?php

declare(strict_types=1);

namespace Phalanx\Testing\Lenses;

use Phalanx\Diagnostics\DoctorReport;
use Phalanx\Diagnostics\EnvironmentDoctor;
use Phalanx\Runtime\Identity\RuntimeAnnotationId;
use Phalanx\Runtime\Identity\RuntimeResourceId;
use Phalanx\Runtime\Memory\ManagedResource;
use Phalanx\Runtime\Memory\ManagedResourceState;
use Phalanx\Supervisor\SupervisorPoolStats;
use Phalanx\Testing\Attribute\Lens;
use Phalanx\Testing\Lens as LensContract;
use Phalanx\Testing\TestApp;
use PHPUnit\Framework\Assert;

/**
 * Environment health reporting backed by EnvironmentDoctor.
 *
 * Surfaces the same checks Runtime runs at boot — runtime hook policy state,
 * memory pressure on managed tables, dropped runtime events, listener
 * failures — but with a test-friendly assertion surface. Use to gate
 * integration tests on a clean Swoole engine before running work.
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
     * so this method should be called from within TestApp::scoped(...)
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
     * teardown contract Runtime enforces in PhalanxTestCase and is safe to call
     * outside a coroutine run.
     */
    public function assertResourcesClean(): self
    {
        $memory = $this->app->runtime()->memory;
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

    public function resource(string $id): ?ManagedResource
    {
        return $this->app->runtime()->memory->resources->get($id);
    }

    /**
     * @return list<ManagedResource>
     */
    public function resources(?RuntimeResourceId $type = null): array
    {
        return $this->app->runtime()->memory->resources->all($type);
    }

    public function resourceCount(?RuntimeResourceId $type = null): int
    {
        return count($this->resources($type));
    }

    public function liveResourceCount(?RuntimeResourceId $type = null): int
    {
        return $this->app->runtime()->memory->resources->liveCount($type);
    }

    public function liveTaskCount(): int
    {
        return $this->app->supervisor()->liveCount();
    }

    public function resourceAnnotation(
        string $resourceId,
        RuntimeAnnotationId $key,
        string $default = '',
    ): string {
        return $this->app->runtime()->memory->resources->annotation($resourceId, $key, $default);
    }

    public function assertNoLiveResources(?RuntimeResourceId $type = null): self
    {
        return $this->assertLiveResourceCount(0, $type);
    }

    public function assertLiveResourceCount(int $expected, ?RuntimeResourceId $type = null): self
    {
        $live = $this->liveResourceCount($type);
        $label = $type?->value() ?? 'any type';

        Assert::assertSame(
            $expected,
            $live,
            "Expected {$expected} live runtime resources for {$label}; {$live} still live.",
        );

        return $this;
    }

    public function assertResourceState(string $resourceId, ManagedResourceState $state): self
    {
        $resource = $this->resource($resourceId);

        Assert::assertNotNull($resource, "Expected runtime resource {$resourceId} to exist.");
        Assert::assertSame(
            $state,
            $resource->state,
            "Expected runtime resource {$resourceId} to be {$state->value}.",
        );

        return $this;
    }

    public function assertResourceAnnotation(
        string $resourceId,
        RuntimeAnnotationId $key,
        string $expected,
    ): self {
        $actual = $this->resourceAnnotation($resourceId, $key);
        $label = $key->value();

        Assert::assertSame(
            $expected,
            $actual,
            "Expected runtime resource {$resourceId} annotation {$label} to match.",
        );

        return $this;
    }

    public function assertNoLiveTasks(): self
    {
        $live = $this->liveTaskCount();

        Assert::assertSame(
            0,
            $live,
            "Expected no live tasks; {$live} still live.",
        );

        return $this;
    }

    public function poolStats(): SupervisorPoolStats
    {
        return $this->app->supervisor()->poolStats();
    }

    public function assertPoolsClean(): self
    {
        $stats = $this->poolStats();
        $borrowedTaskRuns = $stats->taskRun->borrowed;
        $borrowedScopeFrames = $stats->scopeFrame->borrowed;

        Assert::assertSame(
            0,
            $borrowedTaskRuns,
            "Expected no borrowed supervisor task runs; {$borrowedTaskRuns} still borrowed.",
        );
        Assert::assertSame(
            0,
            $borrowedScopeFrames,
            "Expected no borrowed scope frames; {$borrowedScopeFrames} still borrowed.",
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
            ledger: $this->app->supervisor()->ledger,
            memory: $this->app->runtime()->memory,
            supervisor: $this->app->supervisor(),
        );
    }
}
