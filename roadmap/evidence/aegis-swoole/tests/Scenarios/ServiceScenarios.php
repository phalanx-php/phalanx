<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Scenarios;

use AegisSwoole\AppHost;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Tests\Assertions;
use AegisSwoole\Tests\Fixtures\AppNameConfig;
use AegisSwoole\Tests\Fixtures\CounterService;
use AegisSwoole\Tests\Fixtures\DisposableService;
use AegisSwoole\Tests\Fixtures\EagerService;
use AegisSwoole\Tests\Fixtures\Greeter;
use AegisSwoole\Tests\Fixtures\HelloGreeter;
use AegisSwoole\Tests\Fixtures\LifecycleService;
use AegisSwoole\Tests\Harness;
use AegisSwoole\Tests\Result;

class ServiceScenarios
{
    public function __construct(private readonly AppHost $app)
    {
    }

    public function register(Harness $h): void
    {
        $h->add('service.singleton.cache', function (ExecutionScope $scope): Result {
            $a = $scope->service(CounterService::class);
            $b = $scope->service(CounterService::class);
            $err = Assertions::same($a, $b, 'singleton cached within scope');
            return $err === null ? Result::pass() : Result::fail($err);
        });

        $h->add('service.singleton.shared.across.scopes', function (ExecutionScope $scope): Result {
            $a = $scope->service(CounterService::class);
            $other = $this->app->createScope();
            try {
                $b = $other->service(CounterService::class);
                $err = Assertions::same($a, $b, 'singleton shared across scopes');
                return $err === null ? Result::pass() : Result::fail($err);
            } finally {
                $other->dispose();
            }
        });

        $h->add('service.scoped.per.scope', function (ExecutionScope $scope): Result {
            $a = $scope->service(DisposableService::class);
            $other = $this->app->createScope();
            try {
                $b = $other->service(DisposableService::class);
                $err = Assertions::notSame($a, $b, 'scoped instance per scope');
                return $err === null ? Result::pass() : Result::fail($err);
            } finally {
                $other->dispose();
            }
        });

        $h->add('service.scoped.onDispose.fires', function (ExecutionScope $scope): Result {
            $other = $this->app->createScope();
            $svc = $other->service(DisposableService::class);
            $other->dispose();
            $err = Assertions::equals(true, $svc->disposed, 'onDispose hook fired');
            return $err === null ? Result::pass() : Result::fail($err);
        });

        $h->add('service.eager.built.at.startup', function (ExecutionScope $scope): Result {
            $svc = $scope->service(EagerService::class);
            $err = Assertions::equals(true, $svc->built, 'onInit fired during eager startup');
            return $err === null ? Result::pass() : Result::fail($err);
        });

        $h->add('service.alias.resolves.to.concrete', function (ExecutionScope $scope): Result {
            $svc = $scope->service(Greeter::class);
            $errs = [
                Assertions::equals(true, $svc instanceof HelloGreeter, 'alias resolved to HelloGreeter'),
                Assertions::equals('hello world', $svc->greet('world'), 'concrete impl invoked'),
            ];
            foreach ($errs as $e) {
                if ($e !== null) {
                    return Result::fail($e);
                }
            }
            return Result::pass();
        });

        $h->add('service.config.from.context', function (ExecutionScope $scope): Result {
            $cfg = $scope->service(AppNameConfig::class);
            $err = Assertions::equals('aegis-swoole', $cfg->value, 'context fallback applied');
            return $err === null ? Result::pass() : Result::fail($err);
        });

        $h->add('service.lifecycle.onInit.and.onStartup.fired.once', function (ExecutionScope $scope): Result {
            $svc = $scope->service(LifecycleService::class);
            $errs = [
                Assertions::equals(1, $svc->initCount, 'onInit fired exactly once'),
                Assertions::equals(1, $svc->startupCount, 'onStartup fired exactly once'),
                Assertions::equals(0, $svc->shutdownCount, 'onShutdown not yet fired'),
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
