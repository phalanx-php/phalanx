<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Unit;

use Phalanx\Http\Route;
use Phalanx\Http\RouteConfig;
use Phalanx\Http\RouteGroup;
use Phalanx\Task\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouteGroupTest extends TestCase
{
    #[Test]
    public function creates_from_array_with_route_handlers(): void
    {
        $group = RouteGroup::of([
            'GET /users' => new Route(fn: static fn() => 'list'),
            'GET /users/{id}' => new Route(fn: static fn() => 'show'),
        ]);

        $keys = $group->keys();

        $this->assertCount(2, $keys);
        $this->assertContains('GET /users', $keys);
        $this->assertContains('GET /users/{id}', $keys);
    }

    #[Test]
    public function fluent_route_adds_handler(): void
    {
        $group = RouteGroup::create()
            ->route('/users', Task::of(static fn() => 'list'))
            ->route('/users/{id}', Task::of(static fn() => 'show'));

        $this->assertCount(2, $group->keys());
    }

    #[Test]
    public function routes_returns_route_handlers(): void
    {
        $group = RouteGroup::of([
            'GET /users' => new Route(fn: static fn() => 'list'),
        ]);

        $routes = $group->routes();

        $this->assertCount(1, $routes);
        $this->assertArrayHasKey('GET /users', $routes);
    }
}
