<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Integration;

use Phalanx\Application;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\RouteNotFoundException;
use Phalanx\Stoa\ValidationException;
use Phalanx\Stoa\Validator\Param\IntInRange;
use Phalanx\Stoa\Validator\Param\OneOf;
use Phalanx\Tests\Stoa\Fixtures\Routes\ShowRouteId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Verifies that RouteParamValidator instances wired via withPatterns() are run
 * at dispatch time and throw ValidationException with the correct field/message
 * when a parameter fails imperative validation.
 */
final class ParamValidatorDispatchTest extends TestCase
{
    private Application $app;

    #[Test]
    public function param_validator_passes_for_valid_value(): void
    {
        $group = RouteGroup::of([
            'GET /items/{id}' => ShowRouteId::class,
        ])->withPatterns(['id' => new IntInRange(1, 999)]);

        $request = $this->createRequest('GET', '/items/42');
        $scope = $this->app->createScope()->withAttribute('request', $request);

        $result = $scope->execute($group);

        $this->assertSame('42', $result);
    }

    #[Test]
    public function param_validator_pattern_rejects_invalid_shape_before_dispatch(): void
    {
        $group = RouteGroup::of([
            'GET /items/{id}' => ShowRouteId::class,
        ])->withPatterns(['id' => new IntInRange(1, 999)]);

        $request = $this->createRequest('GET', '/items/abc');
        $scope = $this->app->createScope()->withAttribute('request', $request);

        $this->expectException(RouteNotFoundException::class);

        $scope->execute($group);
    }

    #[Test]
    public function param_validator_throws_for_out_of_range_value(): void
    {
        $group = RouteGroup::of([
            'GET /items/{id}' => ShowRouteId::class,
        ])->withPatterns(['id' => new IntInRange(1, 999)]);

        // FastRoute's \d+ pattern will still match 1000, but the imperative
        // range check will reject it.
        $request = $this->createRequest('GET', '/items/1000');
        $scope = $this->app->createScope()->withAttribute('request', $request);

        try {
            $scope->execute($group);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('id', $e->errors);
            $this->assertStringContainsString('999', $e->errors['id'][0]);
        }
    }

    #[Test]
    public function one_of_validator_throws_for_invalid_value(): void
    {
        $group = RouteGroup::of([
            'GET /items/{id}' => ShowRouteId::class,
        ])->withPatterns(['id' => new OneOf('foo', 'bar', 'baz')]);

        $request = $this->createRequest('GET', '/items/qux');
        $scope = $this->app->createScope()->withAttribute('request', $request);

        try {
            $scope->execute($group);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('id', $e->errors);
            $this->assertStringContainsString('foo', $e->errors['id'][0]);
        }
    }

    #[Test]
    public function one_of_validator_passes_for_valid_value(): void
    {
        $group = RouteGroup::of([
            'GET /items/{id}' => ShowRouteId::class,
        ])->withPatterns(['id' => new OneOf('foo', 'bar', 'baz')]);

        $request = $this->createRequest('GET', '/items/bar');
        $scope = $this->app->createScope()->withAttribute('request', $request);

        $result = $scope->execute($group);

        $this->assertSame('bar', $result);
    }

    protected function setUp(): void
    {
        $this->app = Application::starting()->compile();
    }

    protected function tearDown(): void
    {
        $this->app->shutdown();
    }

    private function createRequest(string $method, string $path): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);

        return $request;
    }
}
