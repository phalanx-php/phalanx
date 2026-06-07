<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Support;

use Phalanx\Http\ApplicationBuilder;
use Phalanx\Http\RouteGroup;
use Phalanx\Http\Http;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Testing\TestApp;
use Psr\Http\Message\ServerRequestInterface;

abstract class TestCase extends PhalanxTestCase
{
    private ?TestApp $routeDispatchApplication = null;

    /** @param array<string, mixed> $context */
    protected static function http(array $context = []): ApplicationBuilder
    {
        return Http::starting($context)->withLedger(new InProcessLedger());
    }

    protected function dispatchRoute(RouteGroup $group, ServerRequestInterface $request): mixed
    {
        $this->routeDispatchApplication ??= $this->testApp();

        return $this->routeDispatchApplication->scoped(
            static fn(ExecutionScope $scope): mixed => $group->dispatch($scope, $request),
        );
    }
}
