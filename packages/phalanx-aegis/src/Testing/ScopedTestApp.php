<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use Closure;
use Phalanx\Application;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Scope\ExecutionScope;

final class ScopedTestApp
{
    private bool $shutdownAfterRun = false;

    public function __construct(
        public readonly Application $app,
    ) {
    }

    public function shutdownAfterRun(): self
    {
        $this->shutdownAfterRun = true;

        return $this;
    }

    /**
     * @param Closure(ExecutionScope): void $test
     */
    public function run(Closure $test, ?CancellationToken $token = null): void
    {
        $this->app->startup();
        $scope = $this->app->createScope($token);

        try {
            $test($scope);
        } finally {
            $scope->dispose();

            if ($this->shutdownAfterRun) {
                $this->app->shutdown();
            }
        }
    }
}
