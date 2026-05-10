<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Scope\ExecutionScope;
use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\Stoa;
use Phalanx\Task\Scopeable;
use Phalanx\Task\Task;

/**
 * Failing handler that triggers the rich HTML error page.
 */
final class FailingWebHandler implements Scopeable
{
    public function __invoke(RequestScope $scope): never
    {
        // Layer 1
        $scope->execute(Task::named('business_logic.process', static function (ExecutionScope $scope) {
            // Layer 2
            return $scope->execute(Task::named('gateway.external_api', static function (ExecutionScope $scope) {
                // Background noise to show in the Ledger
                $scope->go(static fn(ExecutionScope $s) => $s->delay(10.0), 'stats.collector');
                
                $scope->delay(0.1);
                throw new \RuntimeException("External API Timeout: Service 'auth-provider' unavailable.");
            }));
        }));
    }
}

return static function (array $context) {
    echo <<<BOOT
Phalanx Server: Stoa Error Diagnostics
Listening on http://127.0.0.1:8189

Try this URL in your browser:
http://127.0.0.1:8189/fail

BOOT;

    return Stoa::starting($context)
        ->routes(['GET /fail' => FailingWebHandler::class])
        ->debug() // Enable high-fidelity error rendering
        ->listen('127.0.0.1:8189')
        ->run();
};
