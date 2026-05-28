<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Scenarios;

use AegisSwoole\Application;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Service\ServiceBundle;
use AegisSwoole\Service\Services;
use AegisSwoole\Tests\Assertions;
use AegisSwoole\Tests\Fixtures\LifecycleService;
use AegisSwoole\Tests\Fixtures\TestBundle;
use AegisSwoole\Tests\Harness;
use AegisSwoole\Tests\Result;

class ApplicationScenarios
{
    private static function emptyBundle(): ServiceBundle
    {
        return new class implements ServiceBundle {
            public function services(Services $services, array $context): void
            {
            }
        };
    }

    public function register(Harness $h): void
    {
        $h->add('application.starting.returns.builder.fluent.chain', function (ExecutionScope $_): Result {
            $bundle = self::emptyBundle();
            $app = Application::starting(['k' => 'v'])->providers($bundle)->compile();
            $err = Assertions::same($bundle, $app->providers()[0], 'fluent chain produces wired Application');
            return $err === null ? Result::pass() : Result::fail($err);
        });

        $h->add('application.providers.list.returned.from.host', function (ExecutionScope $_): Result {
            $bundle = self::emptyBundle();
            $app = Application::starting([])->providers($bundle)->compile();
            $providers = $app->providers();
            $errs = [
                Assertions::equals(1, count($providers), 'one provider registered'),
                Assertions::same($bundle, $providers[0], 'same bundle instance returned'),
            ];
            foreach ($errs as $e) {
                if ($e !== null) {
                    return Result::fail($e);
                }
            }
            return Result::pass();
        });

        $h->add('application.startup.runs.eager.and.onStartup', function (ExecutionScope $_): Result {
            $app = Application::starting([])->providers(new TestBundle())->compile()->startup();
            try {
                $scope = $app->createScope();
                $svc = $scope->service(LifecycleService::class);
                $scope->dispose();
                $errs = [
                    Assertions::equals(1, $svc->initCount, 'onInit fired once'),
                    Assertions::equals(1, $svc->startupCount, 'onStartup fired once'),
                    Assertions::equals(0, $svc->shutdownCount, 'onShutdown not yet fired'),
                ];
            } finally {
                $app->shutdown();
            }
            $errs[] = Assertions::equals(1, $svc->shutdownCount, 'onShutdown fired after shutdown');
            foreach ($errs as $e) {
                if ($e !== null) {
                    return Result::fail($e);
                }
            }
            return Result::pass();
        });

        $h->add('application.startup.idempotent', function (ExecutionScope $_): Result {
            $app = Application::starting([])->providers(new TestBundle())->compile();
            $a = $app->startup();
            $b = $app->startup();
            try {
                $err = Assertions::same($a, $b, 'startup returns same instance both calls');
                return $err === null ? Result::pass() : Result::fail($err);
            } finally {
                $app->shutdown();
            }
        });

        $h->add(
            'application.shutdown.fires.onShutdown.exactly.once.even.if.called.twice',
            function (ExecutionScope $_): Result {
                $app = Application::starting([])->providers(new TestBundle())->compile()->startup();
                $scope = $app->createScope();
                $svc = $scope->service(LifecycleService::class);
                $scope->dispose();
                $app->shutdown();
                $app->shutdown(); // second call: instances already cleared in LazySingleton
                $err = Assertions::equals(1, $svc->shutdownCount, 'onShutdown fired exactly once');
                return $err === null ? Result::pass() : Result::fail($err);
            }
        );

        $h->add('application.scope.returns.fresh.scope.each.call', function (ExecutionScope $_): Result {
            $app = Application::starting([])->providers(self::emptyBundle())->compile();
            try {
                $a = $app->createScope();
                $b = $app->createScope();
                $err = Assertions::notSame($a, $b, 'createScope produces fresh scopes');
                $a->dispose();
                $b->dispose();
                return $err === null ? Result::pass() : Result::fail($err);
            } finally {
                $app->shutdown();
            }
        });
    }
}
