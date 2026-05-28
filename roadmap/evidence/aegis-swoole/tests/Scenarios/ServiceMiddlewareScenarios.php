<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Scenarios;

use AegisSwoole\Application;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Service\ServiceBundle;
use AegisSwoole\Service\Services;
use AegisSwoole\Tests\Assertions;
use AegisSwoole\Tests\Fixtures\CounterService;
use AegisSwoole\Tests\Fixtures\RecordingMiddleware;
use AegisSwoole\Tests\Fixtures\ReplacingMiddleware;
use AegisSwoole\Tests\Harness;
use AegisSwoole\Tests\Result;

class ServiceMiddlewareScenarios
{
    /** @param list<\AegisSwoole\Service\ServiceTransformationMiddleware> $middlewares */
    private static function buildApp(array $middlewares): Application
    {
        $bundle = new class implements ServiceBundle {
            public function services(Services $services, array $context): void
            {
                $services->singleton(CounterService::class)
                    ->factory(static fn() => new CounterService());
            }
        };
        return Application::starting([])
            ->providers($bundle)
            ->serviceMiddleware(...$middlewares)
            ->compile()
            ->startup();
    }

    public function register(Harness $h): void
    {
        $h->add('service.middleware.wraps.resolution', function (ExecutionScope $_): Result {
            $order = [];
            $mw = new RecordingMiddleware('mw1', $order);
            $app = self::buildApp([$mw]);
            try {
                $scope = $app->createScope();
                $scope->service(CounterService::class);
                $scope->dispose();
            } finally {
                $app->shutdown();
            }
            $err = Assertions::arrayEquals(['mw1:before', 'mw1:after'], $order);
            return $err === null ? Result::pass() : Result::fail($err);
        });

        $h->add('service.middleware.chain.order.preserved', function (ExecutionScope $_): Result {
            $order = [];
            $a = new RecordingMiddleware('a', $order);
            $b = new RecordingMiddleware('b', $order);
            $c = new RecordingMiddleware('c', $order);
            $app = self::buildApp([$a, $b, $c]);
            try {
                $scope = $app->createScope();
                $scope->service(CounterService::class);
                $scope->dispose();
            } finally {
                $app->shutdown();
            }
            $err = Assertions::arrayEquals(
                ['a:before', 'b:before', 'c:before', 'c:after', 'b:after', 'a:after'],
                $order,
            );
            return $err === null ? Result::pass() : Result::fail($err);
        });

        $h->add('service.middleware.can.replace.instance', function (ExecutionScope $_): Result {
            $replacement = new CounterService();
            $replacement->count = 99;
            $mw = new ReplacingMiddleware(CounterService::class, $replacement);
            $app = self::buildApp([$mw]);
            try {
                $scope = $app->createScope();
                $resolved = $scope->service(CounterService::class);
                $scope->dispose();
            } finally {
                $app->shutdown();
            }
            $errs = [
                Assertions::same($replacement, $resolved, 'middleware-replaced instance returned'),
                Assertions::equals(99, $resolved->count, 'replacement state preserved'),
            ];
            foreach ($errs as $e) {
                if ($e !== null) {
                    return Result::fail($e);
                }
            }
            return Result::pass();
        });
    }
}
