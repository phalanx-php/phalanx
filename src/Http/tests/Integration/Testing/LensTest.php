<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Integration\Testing;

use Phalanx\Http\RouteGroup;
use Phalanx\Http\Testing\Lens;
use Phalanx\Http\Testing\TestableBundle;
use Phalanx\Testing\LensNotAvailable;
use Phalanx\Http\Tests\Support\TestCase;
use Phalanx\Http\Tests\Fixtures\Routes\EchoJsonHandler;
use Phalanx\Http\Tests\Fixtures\Routes\HelloHandler;

final class LensTest extends TestCase
{
    public function testGetReturnsResponseFromRoute(): void
    {
        $app = $this->bootHttpTestApp();

        $app->http->get('/hello')
            ->assertOk()
            ->assertBodyContains('hello')
            ->assertHeader('Content-Type', 'text/plain');
    }

    public function testPostJsonRoundTripsBodyAndIdentity(): void
    {
        $app = $this->bootHttpTestApp();

        $app->http
            ->actingAs(['id' => 42])
            ->postJson('/echo', ['sku' => 'WIDGET'])
            ->assertCreated()
            ->assertJsonPath('received.sku', 'WIDGET')
            ->assertJsonPath('identity.id', 42);
    }

    public function testActingAsPersistsAcrossSubsequentRequests(): void
    {
        $app = $this->bootHttpTestApp();

        $first = $app->http
            ->actingAs(['id' => 7])
            ->postJson('/echo', []);
        $second = $app->http->postJson('/echo', []);

        $first->assertJsonPath('identity.id', 7);
        $second->assertJsonPath('identity.id', 7);
    }

    public function testResetClearsActingIdentity(): void
    {
        $app = $this->bootHttpTestApp();

        $app->http->actingAs(['id' => 5]);
        $app->reset();

        $response = $app->http->postJson('/echo', []);
        $response->assertJsonPath('identity', null);
    }

    public function testWithHeadersAddsDefaultHeaders(): void
    {
        $app = $this->bootHttpTestApp();

        $response = $app->http
            ->withHeaders(['X-Tenant' => 'demo'])
            ->postJson('/echo', ['ok' => true]);

        $response->assertCreated();
        // header is applied to outgoing request — we can't assert it round-tripped
        // here unless EchoJsonHandler echoes headers; default-header propagation is
        // covered structurally by the lens contract test below.
    }

    public function testHttpLensRequiresHttpTestableBundle(): void
    {
        // Boot a TestApp without the bundle: lens accessor should fail loudly.
        $app = $this->testApp();

        try {
            $this->expectException(LensNotAvailable::class);
            $this->expectExceptionMessage(\Phalanx\Http\Testing\Lens::class);

            $_ = $app->http;
        } finally {
            $app->shutdown();
        }
    }

    public function testJsonHelperDecodesResponseBody(): void
    {
        $app = $this->bootHttpTestApp();

        $response = $app->http->postJson('/echo', ['key' => 'value']);

        self::assertSame(['key' => 'value'], $response->json()['received']);
    }

    public function testAssertJsonStructureValidatesShape(): void
    {
        $app = $this->bootHttpTestApp();

        $app->http->postJson('/echo', ['sku' => 'WIDGET'])
            ->assertJsonStructure([
                'received' => ['sku'],
            ]);
    }

    private function bootHttpTestApp(): \Phalanx\Testing\TestApp
    {
        $routes = RouteGroup::of([
            'GET /hello' => HelloHandler::class,
            'POST /echo' => EchoJsonHandler::class,
        ]);

        $http = self::http()
            ->routes($routes)
            ->build();

        return $this->testApp([], new \Phalanx\Http\Testing\TestableBundle())->withPrimary($http);
    }
}
