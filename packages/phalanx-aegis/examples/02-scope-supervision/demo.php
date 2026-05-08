<?php

declare(strict_types=1);

require __DIR__ . '/../../../../vendor/autoload_runtime.php';

use Phalanx\Application;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Supervisor\TaskTreeFormatter;
use Phalanx\Task\Task;

return static function (array $context): \Closure {
    $ledger = new InProcessLedger();
    $app = Application::starting($context)
        ->withLedger($ledger)
        ->compile();

    return static function () use ($app, $ledger): int {
        $exitCode = $app->run(Task::named(
            'demo.supervision.root',
            static function (ExecutionScope $root) use ($ledger): int {
                $results = $root->concurrent(...[
                    'sleeper' => Task::named(
                        'demo.supervision.sleeper',
                        static function (ExecutionScope $child): int {
                            $child->delay(0.02);
                            return 1;
                        },
                    ),
                    'snapshot' => Task::named(
                        'demo.supervision.snapshot',
                        static function (ExecutionScope $child) use ($ledger): array {
                            $child->delay(0.001);
                            return [
                                'value' => 2,
                                'tree' => (new TaskTreeFormatter())->format($ledger->tree()),
                            ];
                        },
                    ),
                    'fast' => Task::named('demo.supervision.fast', static fn(): int => 3),
                ]);

                $checks = [
                    'concurrent result' => $results['sleeper'] + $results['snapshot']['value'] + $results['fast'] === 6,
                    'task tree captured' => str_contains($results['snapshot']['tree'], 'demo.supervision'),
                ];
                $failed = false;

                foreach ($checks as $label => $ok) {
                    $failed = $failed || !$ok;
                    printf("%s -> %s\n", $label, $ok ? 'ok' : 'failed');
                }

                echo $results['snapshot']['tree'];

                return $failed ? 1 : 0;
            },
        ));

        $cleanupOk = $ledger->liveCount() === 0;
        printf("%s -> %s\n", 'supervisor cleaned', $cleanupOk ? 'ok' : 'failed');

        return ((int) $exitCode) !== 0 || !$cleanupOk ? 1 : 0;
    };
};
