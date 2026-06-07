<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Support;

use Phalanx\Application;
use Phalanx\Http\ApplicationBuilder;
use Phalanx\Http\RouteGroup;
use Phalanx\Http\Server;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Testing\PhalanxTestCase;
use Psr\Http\Message\ServerRequestInterface;

abstract class TestCase extends PhalanxTestCase
{
    private ?Application $routeDispatchApplication = null;

    /** @param array<string, mixed> $context */
    protected static function http(array $context = []): ApplicationBuilder
    {
        return Server::starting($context)->withLedger(new InProcessLedger());
    }

    protected function dispatchRoute(RouteGroup $group, ServerRequestInterface $request): mixed
    {
        $this->routeDispatchApplication ??= $this->testApp()->application;

        return $this->routeDispatchApplication->scoped(
            static fn(ExecutionScope $scope): mixed => $group->dispatch($scope, $request),
        );
    }
}
