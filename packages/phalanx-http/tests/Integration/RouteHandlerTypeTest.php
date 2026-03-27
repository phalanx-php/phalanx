<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Integration;

use Phalanx\Console\Command;
use Phalanx\Console\CommandScope;
use Phalanx\Http\Route;
use Phalanx\Scope;
use Phalanx\Task\Scopeable;
use Phalanx\WebSocket\WsRoute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouteHandlerTypeTest extends TestCase
{
    #[Test]
    public function route_accepts_scopeable(): void
    {
        $handler = new TestScopeableHandler();
        $route = new Route(fn: $handler);

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame($handler, $route->fn);
    }

    #[Test]
    public function route_accepts_closure(): void
    {
        $route = new Route(fn: static fn() => 'ok');

        $this->assertInstanceOf(Route::class, $route);
    }

    #[Test]
    public function command_accepts_scopeable(): void
    {
        $handler = new TestScopeableHandler();
        $command = new Command(fn: $handler);

        $this->assertInstanceOf(Command::class, $command);
        $this->assertSame($handler, $command->fn);
    }

    #[Test]
    public function command_accepts_closure(): void
    {
        $command = new Command(fn: static fn() => 0);

        $this->assertInstanceOf(Command::class, $command);
    }

    #[Test]
    public function ws_route_accepts_scopeable(): void
    {
        $handler = new TestScopeableHandler();
        $route = new WsRoute(fn: $handler);

        $this->assertInstanceOf(WsRoute::class, $route);
        $this->assertSame($handler, $route->fn);
    }

    #[Test]
    public function ws_route_accepts_closure(): void
    {
        $route = new WsRoute(fn: static fn() => null);

        $this->assertInstanceOf(WsRoute::class, $route);
    }
}

/** @internal Test-only invokable */
final class TestScopeableHandler implements Scopeable
{
    public function __invoke(Scope $scope): mixed
    {
        return 'handled';
    }
}
