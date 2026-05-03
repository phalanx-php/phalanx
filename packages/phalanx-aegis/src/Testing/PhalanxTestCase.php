<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use Closure;
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

    /** @return null|Closure(\Phalanx\Service\Services, array<string, mixed>): void */
    protected function phalanxServices(): ?Closure
    {
        return null;
    }
}
