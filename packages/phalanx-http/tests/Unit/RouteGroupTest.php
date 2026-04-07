<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Unit;

use Phalanx\Http\RouteGroup;
use Phalanx\Tests\Http\Fixtures\Routes\StatusList;
use Phalanx\Tests\Http\Fixtures\Routes\StatusShow;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouteGroupTest extends TestCase
{
    #[Test]
    public function creates_from_array_with_class_string_handlers(): void
    {
        $group = RouteGroup::of([
            'GET /users' => StatusList::class,
            'GET /users/{id}' => StatusShow::class,
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
            ->route('/users', StatusList::class)
            ->route('/users/{id}', StatusShow::class);

        $this->assertCount(2, $group->keys());
    }

    #[Test]
    public function routes_returns_route_handlers(): void
    {
        $group = RouteGroup::of([
            'GET /users' => StatusList::class,
        ]);

        $routes = $group->routes();

        $this->assertCount(1, $routes);
        $this->assertArrayHasKey('GET /users', $routes);
    }
}
