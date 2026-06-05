<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use Closure;
use Phalanx\Application;
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

    /** @param array<string, mixed> $context */
    protected function testApp(array $context = [], ServiceBundle ...$bundles): TestApp
    {
        $app = TestApp::boot($context, ...$bundles);
        $this->testApps[] = $app;

        return $app;
    }

    /** @param array<string, mixed> $context */
    protected function application(array $context = [], ServiceBundle ...$bundles): Application
    {
        return $this->testApp($context, ...$bundles)->application;
    }

    /** @param array<string, mixed> $context */
    protected function startedApplication(array $context = [], ServiceBundle ...$bundles): Application
    {
        return $this->application($context, ...$bundles)->startup();
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
