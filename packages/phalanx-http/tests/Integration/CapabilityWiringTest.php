<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Integration;

use Phalanx\Application;
use Phalanx\Http\RouteGroup;
use Phalanx\Http\ValidationException;
use Phalanx\Tests\Http\Fixtures\Routes\RequireApiVersionHandler;
use Phalanx\Tests\Http\Fixtures\Routes\ValidatedHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Verifies that the HTTP invoker enforces the capability interfaces wired
 * during the v0.6.0 review pass: RequiresHeaders and HasValidators run
 * before the handler is invoked, aborting dispatch on failure.
 */
final class CapabilityWiringTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = Application::starting()->compile();
    }

    protected function tearDown(): void
    {
        $this->app->shutdown();
    }

    #[Test]
    public function requires_headers_aborts_when_header_missing(): void
    {
        $group = RouteGroup::of([
            'GET /v' => RequireApiVersionHandler::class,
        ]);

        $request = $this->createRequest('GET', '/v', headers: []);
        $scope = $this->app->createScope()->withAttribute('request', $request);

        try {
            $scope->execute($group);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('X-Api-Version', $e->errors);
            $this->assertSame('This header is required', $e->errors['X-Api-Version'][0]);
        }
    }

    #[Test]
    public function requires_headers_aborts_when_pattern_mismatches(): void
    {
        $group = RouteGroup::of([
            'GET /v' => RequireApiVersionHandler::class,
        ]);

        $request = $this->createRequest('GET', '/v', headers: ['X-Api-Version' => 'beta']);
        $scope = $this->app->createScope()->withAttribute('request', $request);

        try {
            $scope->execute($group);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('X-Api-Version', $e->errors);
            $this->assertStringContainsString('pattern', $e->errors['X-Api-Version'][0]);
        }
    }

    #[Test]
    public function requires_headers_passes_when_header_matches(): void
    {
        $group = RouteGroup::of([
            'GET /v' => RequireApiVersionHandler::class,
        ]);

        $request = $this->createRequest('GET', '/v', headers: ['X-Api-Version' => 'v2']);
        $scope = $this->app->createScope()->withAttribute('request', $request);

        $result = $scope->execute($group);

        $this->assertSame(['ok' => true], $result);
    }

    #[Test]
    public function has_validators_runs_validator_before_handler(): void
    {
        $group = RouteGroup::of([
            'GET /v' => ValidatedHandler::class,
        ]);

        $request = $this->createRequest('GET', '/v');
        $scope = $this->app->createScope()->withAttribute('request', $request);

        try {
            $scope->execute($group);
            $this->fail('Expected ValidationException from validator');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('test_field', $e->errors);
            $this->assertSame('validator ran', $e->errors['test_field'][0]);
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function createRequest(string $method, string $path, array $headers = []): ServerRequestInterface
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn(string $name): string => $headers[$name] ?? ''
        );

        return $request;
    }
}
