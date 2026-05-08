<?php

declare(strict_types=1);

require __DIR__ . '/../../../../vendor/autoload_runtime.php';

use Phalanx\Application;
use Phalanx\Boot\AppContext;
use Phalanx\Diagnostics\EnvironmentDoctor;
use Phalanx\Runtime\RuntimePolicy;
use Phalanx\Task\Task;

return static function (array $context): \Closure {
    $appContext = AppContext::fromSymfonyRuntime($context);
    $policy = RuntimePolicy::fromContext($appContext);
    $app = Application::starting($appContext)
        ->withRuntimePolicy($policy)
        ->compile();

    return static function () use ($app, $policy): int {
        return (int) $app->run(Task::named(
            'demo.runtime-policy',
            static function () use ($app, $policy): int {
                $report = (new EnvironmentDoctor($app->supervisor()->ledger, $policy, $app->runtime()->memory))->check();
                $failed = !$report->isHealthy();

                foreach ($report as $check) {
                    if (
                        !str_starts_with($check->name, 'openswoole.')
                        && !str_starts_with($check->name, 'runtime.resources.')
                        && !str_starts_with($check->name, 'runtime.events.')
                        && !str_starts_with($check->name, 'runtime.memory.')
                    ) {
                        continue;
                    }

                    printf(
                        "%s -> %s %s\n",
                        $check->name,
                        $check->ok ? 'ok' : 'failed',
                        $check->detail,
                    );
                }

                return $failed ? 1 : 0;
            },
        ));
    };
};
