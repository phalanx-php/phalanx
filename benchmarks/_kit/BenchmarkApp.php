<?php

declare(strict_types=1);

namespace Phalanx\Benchmarks\Kit;

use Phalanx\Http\RouteGroup;
use Phalanx\Http\HttpRunner;

/**
 * Specialized harness for HTTP benchmarks that provides Http-specific helpers.
 */
final class BenchmarkApp extends BenchmarkHarness
{
    /** @var array<string, HttpRunner> */
    private array $httpRunners = [];

    public function httpRunner(string $name, RouteGroup $routes): HttpRunner
    {
        return $this->httpRunners[$name] ??= HttpRunner::from($this->application())
            ->withRoutes($routes);
    }

    public function shutdown(): void
    {
        foreach ($this->httpRunners as $runner) {
            $runner->stop();
        }

        $this->httpRunners = [];
        parent::shutdown();
    }
}
