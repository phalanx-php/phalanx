<?php

declare(strict_types=1);

require __DIR__ . '/../../../../vendor/autoload_runtime.php';

use Phalanx\Application;
use Phalanx\Boot\AppContext;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Task\Task;

return static function (array $context): \Closure {
    $appContext = AppContext::fromSymfonyRuntime($context);
    $ledger = new InProcessLedger();
    $app = Application::starting($appContext)
        ->withLedger($ledger)
        ->compile();

    return static function () use ($app, $ledger): int {
        $exitCode = $app->run(Task::named(
            'demo.cancellation.root',
            static function (ExecutionScope $root) use ($ledger): int {
                try {
                    $root->timeout(0.01, Task::named(
                        'demo.cancellation.sleeper',
                        static function (ExecutionScope $child): void {
                            $child->delay(1.0);
                        },
                    ));

                    $message = 'not cancelled';
                } catch (Cancelled $e) {
                    $message = $e->getMessage();
                }

                $checks = [
                    'timeout raised cancellation' => str_contains($message, 'timeout after'),
                ];
                $failed = false;

                foreach ($checks as $label => $ok) {
                    $failed = $failed || !$ok;
                    printf("%s -> %s\n", $label, $ok ? 'ok' : 'failed');
                }

                return $failed ? 1 : 0;
            },
        ));

        $cleanupOk = $ledger->liveCount() === 0;
        printf("%s -> %s\n", 'supervisor cleaned', $cleanupOk ? 'ok' : 'failed');

        return ((int) $exitCode) !== 0 || !$cleanupOk ? 1 : 0;
    };
};
