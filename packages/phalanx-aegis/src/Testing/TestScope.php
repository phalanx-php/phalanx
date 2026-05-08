<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use Closure;
use Phalanx\Application;
use Phalanx\Boot\AppContext;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Supervisor\LedgerStorage;

final class TestScope
{
    private function __construct()
    {
    }

    public static function compile(
        ?Closure $services = null,
        AppContext $context = new AppContext(),
        ?LedgerStorage $ledger = null,
    ): ScopedTestApp {
        $builder = Application::starting($context->values);

        if ($services !== null) {
            $builder = $builder->providers(new InlineServiceBundle($services));
        }

        if ($ledger !== null) {
            $builder = $builder->withLedger($ledger);
        }

        $app = $builder->compile();

        return new ScopedTestApp($app);
    }

    public static function run(
        Closure $test,
        ?Closure $services = null,
        AppContext $context = new AppContext(),
        ?CancellationToken $token = null,
    ): void {
        self::compile($services, $context)
            ->shutdownAfterRun()
            ->run($test, $token);
    }
}
