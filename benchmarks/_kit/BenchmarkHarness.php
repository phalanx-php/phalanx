<?php

declare(strict_types=1);

namespace Phalanx\Benchmarks\Kit;

use Phalanx\Application;
use Phalanx\Boot\AppContext;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Stoa\Runtime\Identity\StoaResourceSid;
use Phalanx\Supervisor\TaskTreeFormatter;
use RuntimeException;

/**
 * Provides stability assertions and cleanup verification for benchmarks.
 */
class BenchmarkHarness
{
    /** @var array<int, Application> */
    private array $applications = [];

    private ?Application $primaryApp = null;

    public function __construct(protected readonly AppContext $context)
    {
    }

    public function track(Application $app): void
    {
        $this->applications[spl_object_id($app)] = $app;
    }

    public function ensureApplication(): Application
    {
        if ($this->primaryApp === null) {
            $this->useApp(\Phalanx\Application::starting($this->context->values)->compile()->startup());
        }

        return $this->primaryApp;
    }

    public function useApp(Application $app): void
    {
        $this->primaryApp = $app;
        $this->track($app);
    }

    public function application(): Application
    {
        return $this->ensureApplication();
    }

    public function scope(): ExecutionScope
    {
        return $this->application()->createScope();
    }

    /**
     * Asserts that the tracked applications are in a clean state.
     */
    public function assertClean(string $case): void
    {
        foreach ($this->applications as $application) {
            // 1. Check live resources (specifically Stoa requests if available)
            if (class_exists(StoaResourceSid::class)) {
                $liveRequests = $application->runtime()->memory->resources->liveCount(StoaResourceSid::HttpRequest);
                if ($liveRequests !== 0) {
                    throw new RuntimeException(
                        "Benchmark case '{$case}' left {$liveRequests} live Stoa request resources.",
                    );
                }
            }

            // 2. Check Supervisor task tree
            $supervisor = $application->supervisor();
            if ($supervisor->liveCount() !== 0 || $supervisor->tree() !== []) {
                $formatted = (new TaskTreeFormatter())->format($supervisor->tree());

                throw new RuntimeException(
                    "Benchmark case '{$case}' left live or unreaped task runs:\n{$formatted}",
                );
            }
        }
    }

    public function shutdown(): void
    {
        foreach ($this->applications as $application) {
            $application->shutdown();
        }

        $this->applications = [];
        $this->primaryApp = null;
    }
}
