<?php

declare(strict_types=1);

namespace Phalanx\Testing\Lenses;

use Phalanx\Scope\CoroutineScopeRegistry;
use Phalanx\Scope\Scope;
use Phalanx\Testing\Attribute\Lens;
use Phalanx\Testing\Lens as LensContract;
use Phalanx\Testing\TestApp;
use PHPUnit\Framework\Assert;

/**
 * Coroutine-local scope inspection.
 *
 * Reports the scope visible to the current coroutine via
 * CoroutineScopeRegistry, and verifies that all scopes registered with the
 * Application's supervisor have been disposed. Useful for tests that drive
 * scope-spawning work and need to confirm cleanup before teardown.
 */
#[Lens(
    accessor: 'scope',
    returns: self::class,
    factory: ScopeLensFactory::class,
    requires: [],
)]
final class ScopeLens implements LensContract
{
    public function __construct(private readonly TestApp $app)
    {
    }

    public function current(): ?Scope
    {
        return CoroutineScopeRegistry::current();
    }

    public function liveCount(): int
    {
        return $this->app->application->supervisor()->liveScopeCount();
    }

    public function assertDisposed(): self
    {
        $live = $this->liveCount();

        Assert::assertSame(
            0,
            $live,
            "Expected no live scopes; supervisor reports {$live}.",
        );

        return $this;
    }

    public function assertCurrentMatches(Scope $expected): self
    {
        Assert::assertSame($expected, $this->current(), 'Active coroutine scope did not match expectation.');

        return $this;
    }

    public function reset(): void
    {
    }
}
