<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Phalanx\Application;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Task\Task;

$context = [
    'argv' => $argv ?? [],
];

$exitCode = Application::starting($context)
    ->withLedger($ledger = new InProcessLedger())
    ->run(Task::named(
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

exit(((int) $exitCode) !== 0 || !$cleanupOk ? 1 : 0);
