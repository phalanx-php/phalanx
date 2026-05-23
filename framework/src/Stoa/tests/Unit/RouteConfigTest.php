<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Unit;

use Phalanx\Stoa\RouteConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouteConfigTest extends TestCase
{
    #[Test]
    public function compiles_simple_path_for_fast_route(): void
    {
        $config = RouteConfig::compile('/users', 'GET');

        self::assertSame(['GET'], $config->methods);
        self::assertSame('/users', $config->path);
        self::assertSame('/users', $config->fastRoutePath);
        self::assertSame([], $config->paramNames);
    }

    #[Test]
    public function compiles_path_params_for_fast_route(): void
    {
        $config = RouteConfig::compile('/users/{userId}/posts/{postId}', 'GET');

        self::assertSame('/users/{userId}/posts/{postId}', $config->fastRoutePath);
        self::assertSame(['userId', 'postId'], $config->paramNames);
    }

    #[Test]
    public function compiles_literal_regex_constraints_for_fast_route(): void
    {
        $config = RouteConfig::compile('/users/{id:\d+}', 'GET');

        self::assertSame('/users/{id:\d+}', $config->fastRoutePath);
        self::assertSame(['id'], $config->paramNames);
    }

    #[Test]
    public function expands_named_alias_constraints_for_fast_route(): void
    {
        $config = RouteConfig::compile('/users/{id:int}', 'GET', ['int' => '\d+']);

        self::assertSame('/users/{id:\d+}', $config->fastRoutePath);
        self::assertSame(['id'], $config->paramNames);
    }

    #[Test]
    public function applies_parameter_name_constraints_for_fast_route(): void
    {
        $config = RouteConfig::compile('/users/{id}', 'GET', ['id' => '\d+']);

        self::assertSame('/users/{id:\d+}', $config->fastRoutePath);
        self::assertSame(['id'], $config->paramNames);
    }

    #[Test]
    public function compiles_multiple_methods(): void
    {
        $config = RouteConfig::compile('/users', ['get', 'post']);

        self::assertSame(['GET', 'POST'], $config->methods);
    }
}
