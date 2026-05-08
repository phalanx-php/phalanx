<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use Closure;
use Phalanx\Service\ServiceBundle;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

abstract class PhalanxTestCase extends TestCase
{
    protected PhalanxTestRuntime $phalanx {
        get => $this->phalanxRuntime ??= PhalanxTestRuntime::start(
            services: $this->phalanxServices(),
            context: $this->phalanxContext(),
        );
    }

    protected PhalanxTestScope $scope {
        get => $this->phalanx->scope;
    }

    private ?PhalanxTestRuntime $phalanxRuntime = null;

    /** @var list<TestApp> */
    private array $testApps = [];

    /**
     * Boot a fresh TestApp for the current test. One TestApp is booted per
     * call; each gets its own Application and is torn down in #[After]. Use
     * separate calls to model multi-app scenarios.
     *
     * @param array<string, mixed> $context
     */
    protected function testApp(array $context = [], ServiceBundle ...$bundles): TestApp
    {
        $app = TestApp::boot($context, ...$bundles);
        $this->testApps[] = $app;

        return $app;
    }

    #[After]
    protected function shutdownTestApps(): void
    {
        $apps = $this->testApps;
        $this->testApps = [];

        foreach ($apps as $app) {
            $app->shutdown();
        }
    }

    #[After]
    protected function shutdownPhalanxRuntime(): void
    {
        if ($this->phalanxRuntime === null) {
            return;
        }

        try {
            $this->phalanxRuntime->scope->expect->scope()->disposed();
            $this->phalanxRuntime->scope->expect->work()->finished();
            $this->phalanxRuntime->scope->expect->leases()->released();
            $this->phalanxRuntime->scope->expect->diagnostics()->healthy();
            $this->phalanxRuntime->scope->expect->runtime()->clean();
        } finally {
            $this->phalanxRuntime->shutdown();
            $this->phalanxRuntime = null;
        }
    }

    /** @return array<string, mixed> */
    protected function phalanxContext(): array
    {
        return [];
    }

    /** @return null|Closure(\Phalanx\Service\Services, \Phalanx\Boot\AppContext): void */
    protected function phalanxServices(): ?Closure
    {
        return null;
    }
}
