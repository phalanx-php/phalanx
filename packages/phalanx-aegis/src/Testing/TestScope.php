<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use Closure;
use Phalanx\Application;
use Phalanx\Concurrency\CancellationToken;

final class TestScope
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $context
     */
    public static function compile(?Closure $services = null, array $context = []): ScopedTestApp
    {
        $builder = Application::starting($context);

        if ($services !== null) {
            $builder = $builder->providers(new InlineServiceBundle($services));
        }

        $app = $builder->compile();

        return new ScopedTestApp($app);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function run(
        Closure $test,
        ?Closure $services = null,
        array $context = [],
        ?CancellationToken $token = null,
    ): void {
        self::compile($services, $context)
            ->shutdownAfterRun()
            ->run($test, $token);
    }
}
