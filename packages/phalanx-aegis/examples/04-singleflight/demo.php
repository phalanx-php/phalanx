<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Phalanx\Application;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Task\Task;

$context = [
    'argv' => $argv ?? [],
];
$ledger = new InProcessLedger();

$exitCode = Application::starting($context)
    ->withLedger($ledger)
    ->run(Task::named(
        'demo.singleflight.root',
        static function (ExecutionScope $root) use ($ledger): int {
            $tasks = [];
            for ($i = 0; $i < 5; $i++) {
                $tasks[] = Task::named(
                    "demo.singleflight.waiter.{$i}",
                    static fn(ExecutionScope $waiter): object => $waiter->singleflight(
                        'demo:shared',
                        Task::named(
                            'demo.singleflight.owner',
                            static function (ExecutionScope $owner): object {
                                $owner->delay(0.02);
                                return new \stdClass();
                            },
                        ),
                    ),
                );
            }

            $results = $root->concurrent($tasks);
            $objectIds = array_map(static fn(object $result): int => spl_object_id($result), $results);
            $checks = [
                'all waiters share result' => count(array_unique($objectIds)) === 1,
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
