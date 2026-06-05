<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload_runtime.php';

use Phalanx\Http\Http;
use Phalanx\Http\HttpServerConfig;
use Phalanx\Task\Task;

if (!class_exists('IgnitionDemoHandler')) {
    final class IgnitionDemoHandler implements \Phalanx\Task\Scopeable
    {
        public function __invoke(\Phalanx\Scope\Scope $scope): mixed
        {
            $es = $scope instanceof \Phalanx\Http\RequestContext ? $scope : throw new \Exception('Expected RequestContext');

            return $es->execute(Task::of(static function (\Phalanx\Scope\ExecutionScope $es) {

                return $es->concurrent(
                    Task::of(static function (\Phalanx\Scope\ExecutionScope $es) {
                        $es->call(static fn() => usleep(10000), \Phalanx\Supervisor\WaitReason::custom('remote fetch: simulating'));
                        return $es->execute(Task::of(static function (\Phalanx\Scope\ExecutionScope $_es) {
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

return static fn(array $context): \Closure => static function () use ($context): int {
    return Http::starting($context)
        ->routes(['GET /fail' => IgnitionDemoHandler::class])
        ->withServerConfig(new HttpServerConfig(
            ignitionEnabled: true,
            logoPath: '/logo.svg',
            faviconPath: 'https://raw.githubusercontent.com/phalanx-php/phalanx/refs/heads/main/mark.png',
            tagline: 'High-performance async application framework for PHP 8.4+',
            githubUrl: 'https://github.com/phalanx-php/phalanx',
        ))
        ->listen('127.0.0.1:8189')
        ->withBanner(<<<'BANNER'
            Phalanx Server: Spatie Ignition Diagnostics
            Listening on {url}

            Try this URL in your browser:
            {url}/fail
            BANNER)
        ->run();
};
