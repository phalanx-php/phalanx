<?php

declare(strict_types=1);

namespace Phalanx\Demos\Kit;

use Closure;
use Phalanx\Boot\AppContext;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Service\ServiceBundle;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Testing\Lenses\LedgerLens;
use Phalanx\Testing\Lenses\RuntimeLens;
use Phalanx\Testing\Lenses\ScopeLens;
use Phalanx\Testing\TestApp;

/**
 * Demo-side counterpart to TestApp. Composes Aegis through the same lens
 * machinery, but without PhalanxTestCase's reset/shutdown lifecycle.
 *
 * Demos own their lifecycle explicitly: boot(), zero or more run() calls,
 * then shutdown(). run() delegates to Application::scoped(), which leaves
 * the host alive after the task body returns so post-run lens reads
 * (ledger / scope / runtime) see real state. The default SwooleTableLedger
 * is destroyed at Application::shutdown(), so any lens read must happen
 * before shutdown() is called.
 */
final class DemoApp
{
    private function __construct(private(set) TestApp $inner)
    {
    }

    /**
     * Top-level demo entry. Returns the Symfony Runtime-shape outer
     * closure: when the runtime invokes it with the merged process+.env
     * context, it boots a DemoApp + DemoReport, runs $body against them,
     * and yields the report's exit code. Lifecycle is fully owned by the
     * kit — shutdown() runs in a finally block so cleanup happens even on
     * $body throws.
     *
     * The body receives the typed AppContext as a third positional so
     * preflight checks (binary detection, env validation) can run inside
     * the body and short-circuit via $report->cannotRun(...).
     *
     * @param Closure(self, DemoReport, AppContext): void $body
     * @param list<ServiceBundle>                          $bundles
     */
    public static function boot(string $reportTitle, Closure $body, array $bundles = []): Closure
    {
        return static fn (array $context): Closure =>
            static function () use ($context, $reportTitle, $body, $bundles): int {
                $app = new self(TestApp::boot($context, ...$bundles));
                $report = new DemoReport($reportTitle);

                try {
                    $body($app, $report, new AppContext($context));
                } finally {
                    $app->shutdown();
                }

                return $report->exitCode();
            };
    }

    public function withPrimary(object $primary): self
    {
        $this->inner->withPrimary($primary);

        return $this;
    }

    public function run(Scopeable|Executable|Closure $task, ?CancellationToken $token = null): mixed
    {
        return $this->inner->application->scoped($task, $token);
    }

    public function ledger(): LedgerLens
    {
        return $this->inner->lens(LedgerLens::class);
    }

    public function scope(): ScopeLens
    {
        return $this->inner->lens(ScopeLens::class);
    }

    public function runtime(): RuntimeLens
    {
        return $this->inner->lens(RuntimeLens::class);
    }

    public function shutdown(): void
    {
        $this->inner->shutdown();
    }
}
