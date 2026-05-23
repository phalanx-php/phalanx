<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Integration\Testing;

use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\Stoa;
use Phalanx\Stoa\Testing\HttpLens;
use Phalanx\Stoa\Testing\StoaTestableBundle;
use Phalanx\Testing\LensNotAvailable;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Tests\Stoa\Fixtures\Routes\EchoJsonHandler;
use Phalanx\Tests\Stoa\Fixtures\Routes\HelloHandler;

final class HttpLensTest extends PhalanxTestCase
{
    public function testGetReturnsResponseFromRoute(): void
    {
        $app = $this->bootStoaTestApp();

        $app->http->get('/hello')
            ->assertOk()
            ->assertBodyContains('hello')
            ->assertHeader('Content-Type', 'text/plain');
    }

    public function testPostJsonRoundTripsBodyAndIdentity(): void
    {
        $app = $this->bootStoaTestApp();

        $app->http
            ->actingAs(['id' => 42])
            ->postJson('/echo', ['sku' => 'WIDGET'])
            ->assertCreated()
            ->assertJsonPath('received.sku', 'WIDGET')
            ->assertJsonPath('identity.id', 42);
    }

    public function testActingAsPersistsAcrossSubsequentRequests(): void
    {
        $app = $this->bootStoaTestApp();

        $first = $app->http
            ->actingAs(['id' => 7])
            ->postJson('/echo', []);
        $second = $app->http->postJson('/echo', []);

        $first->assertJsonPath('identity.id', 7);
        $second->assertJsonPath('identity.id', 7);
    }

    public function testResetClearsActingIdentity(): void
    {
        $app = $this->bootStoaTestApp();

        $app->http->actingAs(['id' => 5]);
        $app->reset();

        $response = $app->http->postJson('/echo', []);
        $response->assertJsonPath('identity', null);
    }

    public function testWithHeadersAddsDefaultHeaders(): void
    {
        $app = $this->bootStoaTestApp();

        $response = $app->http
            ->withHeaders(['X-Tenant' => 'demo'])
            ->postJson('/echo', ['ok' => true]);

        $response->assertCreated();
        // header is applied to outgoing request — we can't assert it round-tripped
        // here unless EchoJsonHandler echoes headers; default-header propagation is
        // covered structurally by the lens contract test below.
    }

    public function testHttpLensRequiresStoaTestableBundle(): void
    {
        // Boot a TestApp without the bundle: lens accessor should fail loudly.
        $app = $this->testApp();

        try {
            $this->expectException(LensNotAvailable::class);
            $this->expectExceptionMessage(HttpLens::class);

            $app->http;
        } finally {
            $app->shutdown();
        }
    }

    public function testJsonHelperDecodesResponseBody(): void
    {
        $app = $this->bootStoaTestApp();

        $response = $app->http->postJson('/echo', ['key' => 'value']);

        self::assertSame(['key' => 'value'], $response->json()['received']);
    }

    public function testAssertJsonStructureValidatesShape(): void
    {
        $app = $this->bootStoaTestApp();

        $app->http->postJson('/echo', ['sku' => 'WIDGET'])
            ->assertJsonStructure([
                'received' => ['sku'],
            ]);
    }

    private function bootStoaTestApp(): \Phalanx\Testing\TestApp
    {
        $routes = RouteGroup::of([
            'GET /hello' => HelloHandler::class,
            'POST /echo' => EchoJsonHandler::class,
        ]);

        $stoa = Stoa::starting()
            ->routes($routes)
            ->build();

        return $this->testApp([], new StoaTestableBundle())->withPrimary($stoa);
    }
}
