<?php

declare(strict_types=1);

use Phalanx\Stoa\ExecutionContext;
use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\Stoa;
use Phalanx\Task\Task;

require __DIR__ . '/../../../vendor/autoload.php';

/**
 * Demo handler that deliberately fails to trigger diagnostics.
 */
final class IgnitionDemoHandler
{
    public function __invoke(ExecutionContext $scope): mixed
    {
        // 1. A deep nested task tree for Ledger visualization
        return $scope->execute(Task::of(static function (RequestScope $es) {
            
            // 2. Parallel tasks to show concurrency in Ledger
            return $es->concurrent(
                Task::of(static function (RequestScope $es) {
                    $es->call(static fn() => usleep(10000), 'simulating remote fetch');
                    return $es->execute(Task::of(static function (RequestScope $es) {
                         // 3. The actual point of failure
                         throw new RuntimeException(
                             "Stripe API unreachable: Peer certificate cannot be authenticated with given CA certificates."
                         );
                    }));
                }),
                Task::of(static function (RequestScope $es) {
                    $es->call(static fn() => usleep(5000), 'processing audit log');
                })
            );
        }));
    }
}

return static function (array $context) {
    echo <<<BOOT
Phalanx Server: Spatie Ignition Diagnostics
Listening on http://127.0.0.1:8189

Try this URL in your browser:
http://127.0.0.1:8189/fail

BOOT;

    return Stoa::starting($context)
        ->routes(['GET /fail' => IgnitionDemoHandler::class])
        ->debug() // Enable Ignition
        ->listen('127.0.0.1:8189')
        ->run();
};
