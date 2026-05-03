<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Phalanx\Application;
use Phalanx\Diagnostics\EnvironmentDoctor;
use Phalanx\Runtime\RuntimePolicy;
use Phalanx\Task\Task;

$context = [
    'argv' => $argv ?? [],
];
$policy = RuntimePolicy::fromContext($context);
$app = Application::starting($context)
    ->withRuntimePolicy($policy)
    ->compile();

$exitCode = $app->run(Task::named(
    'demo.runtime-policy',
    static function () use ($app, $policy): int {
        $report = (new EnvironmentDoctor($app->supervisor()->ledger, $policy, $app->runtime()->memory))->check();
        $failed = !$report->isHealthy();

        foreach ($report as $check) {
            if (!str_starts_with($check->name, 'openswoole.') && !str_starts_with($check->name, 'runtime.memory.')) {
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

exit((int) $exitCode);
