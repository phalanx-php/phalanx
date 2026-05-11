<?php

declare(strict_types=1);

use Phalanx\Stoa\ExecutionContext;
use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\Stoa;
use Phalanx\Stoa\StoaServerConfig;
use Phalanx\Task\Task;

require __DIR__ . '/../../../vendor/autoload_runtime.php';

/**
 * Demo handler that deliberately fails to trigger diagnostics.
 */
if (!class_exists('IgnitionDemoHandler')) {
    final class IgnitionDemoHandler implements \Phalanx\Task\Scopeable
    {
        public function __invoke(\Phalanx\Scope\Scope $scope): mixed
        {
            $es = $scope instanceof \Phalanx\Stoa\RequestScope ? $scope : throw new \Exception('Expected RequestScope');
            
            // Ledger Lvl 1
            return $es->execute(Task::of(static function (\Phalanx\Scope\ExecutionScope $es) {
                
                // Ledger Lvl 2
                return $es->concurrent(
                    Task::of(static function (\Phalanx\Scope\ExecutionScope $es) {
                        $es->call(static fn() => usleep(10000), \Phalanx\Supervisor\WaitReason::custom('remote fetch: simulating'));
                        return $es->execute(Task::of(static function (\Phalanx\Scope\ExecutionScope $es) {
                             // Ledger lvl 3
                             throw new RuntimeException(
                                 "Stripe API unreachable: Peer certificate cannot be authenticated with given CA certificates."
                             );
                        }));
                    }),
                    Task::of(static function (\Phalanx\Scope\ExecutionScope $es) {
                        $es->call(static fn() => usleep(5000), \Phalanx\Supervisor\WaitReason::custom('audit log: processing'));
                    })
                );            
            }));
        }
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
        ->withServerConfig(new StoaServerConfig(
            ignitionEnabled: true,
            logoPath: '/logo.svg',
            faviconPath: 'https://raw.githubusercontent.com/phalanx-php/phalanx/refs/heads/main/mark.png',
            tagline: 'High-performance async application framework for PHP 8.4+',
            githubUrl: 'https://github.com/phalanx-php/phalanx',
        ))
        ->listen('127.0.0.1:8189')
        ->run();
};
