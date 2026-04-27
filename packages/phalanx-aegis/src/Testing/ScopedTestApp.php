<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use Closure;
use InvalidArgumentException;
use Phalanx\AppHost;
use Phalanx\Concurrency\CancellationToken;
use Phalanx\ExecutionScope;
use ReflectionFunction;

use function React\Async\async;
use function React\Async\await;

final class ScopedTestApp
{
    private bool $shutdownOnRunComplete = false;

    public function __construct(
        private readonly AppHost $app,
    ) {}

    public function run(Closure $test, ?CancellationToken $token = null): void
    {
        self::enforceStaticClosure($test);

        [$app, $scope] = $this->app->boot($token);

        try {
            await(async(static fn() => $test($scope))());
        } finally {
            $scope->dispose();

            if ($this->shutdownOnRunComplete) {
                $app->shutdown();
            }
        }
    }

    public function shutdown(): void
    {
        $this->app->shutdown();
    }

    /**
     * @internal Used by TestScope::run() to auto-shutdown after the single run completes.
     */
    public function shutdownAfterRun(): self
    {
        $this->shutdownOnRunComplete = true;

        return $this;
    }

    private static function enforceStaticClosure(Closure $closure): void
    {
        $rf = new ReflectionFunction($closure);

        if ($rf->getClosureThis() !== null) {
            throw new InvalidArgumentException(
                'Test closure must be static to prevent reference cycles. ' .
                'Use: static function(ExecutionScope $scope) { ... } with Assert::assert*() for assertions.',
            );
        }
    }
}
