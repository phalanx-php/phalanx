<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Unit;

use Phalanx\Stoa\RouteConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouteConfigTest extends TestCase
{
    #[Test]
    public function compiles_simple_path(): void
    {
        $config = RouteConfig::compile('/users', 'GET');

        $this->assertSame(['GET'], $config->methods);
        $this->assertSame('#^/users$#', $config->pattern);
        $this->assertSame([], $config->paramNames);
    }

    #[Test]
    public function compiles_path_with_single_param(): void
    {
        $config = RouteConfig::compile('/users/{id}', 'GET');

        $this->assertSame('#^/users/(?P<id>[^/]+)$#', $config->pattern);
        $this->assertSame(['id'], $config->paramNames);
    }

    #[Test]
    public function compiles_path_with_multiple_params(): void
    {
        $config = RouteConfig::compile('/users/{userId}/posts/{postId}', 'GET');

        $this->assertSame('#^/users/(?P<userId>[^/]+)/posts/(?P<postId>[^/]+)$#', $config->pattern);
        $this->assertSame(['userId', 'postId'], $config->paramNames);
    }

    #[Test]
    public function compiles_path_with_constrained_param(): void
    {
        $config = RouteConfig::compile('/users/{id:\d+}', 'GET');

        $this->assertSame('#^/users/(?P<id>\d+)$#', $config->pattern);
        $this->assertSame(['id'], $config->paramNames);
    }

    #[Test]
    public function compiles_multiple_methods(): void
    {
        $config = RouteConfig::compile('/users', ['GET', 'POST']);

        $this->assertSame(['GET', 'POST'], $config->methods);
    }

    #[Test]
    public function normalizes_method_case(): void
    {
        $config = RouteConfig::compile('/users', 'get');

        $this->assertSame(['GET'], $config->methods);
    }

    #[Test]
    public function matches_exact_path(): void
    {
        $config = RouteConfig::compile('/users', 'GET');

        $params = $config->matches('GET', '/users');

        $this->assertSame([], $params);
    }

    #[Test]
    public function matches_path_with_params(): void
    {
        $config = RouteConfig::compile('/users/{id}', 'GET');

        $params = $config->matches('GET', '/users/42');

        $this->assertSame(['id' => '42'], $params);
    }

    #[Test]
    public function matches_path_with_multiple_params(): void
    {
        $config = RouteConfig::compile('/users/{userId}/posts/{postId}', 'GET');

        $params = $config->matches('GET', '/users/5/posts/123');

        $this->assertSame(['userId' => '5', 'postId' => '123'], $params);
    }

    #[Test]
    public function returns_null_for_wrong_method(): void
    {
        $config = RouteConfig::compile('/users', 'GET');

        $params = $config->matches('POST', '/users');

        $this->assertNull($params);
    }

    #[Test]
    public function returns_null_for_non_matching_path(): void
    {
        $config = RouteConfig::compile('/users', 'GET');

        $params = $config->matches('GET', '/posts');

        $this->assertNull($params);
    }

    #[Test]
    public function returns_null_for_partial_match(): void
    {
        $config = RouteConfig::compile('/users', 'GET');

        $this->assertNull($config->matches('GET', '/users/extra'));
        $this->assertNull($config->matches('GET', '/prefix/users'));
    }

    #[Test]
    public function constrained_param_rejects_invalid_values(): void
    {
        $config = RouteConfig::compile('/users/{id:\d+}', 'GET');

        $this->assertNull($config->matches('GET', '/users/abc'));
        $this->assertSame(['id' => '42'], $config->matches('GET', '/users/42'));
    }

    #[Test]
    public function matches_any_allowed_method(): void
    {
        $config = RouteConfig::compile('/users', ['GET', 'POST']);

        $this->assertSame([], $config->matches('GET', '/users'));
        $this->assertSame([], $config->matches('POST', '/users'));
        $this->assertNull($config->matches('DELETE', '/users'));
    }
}
