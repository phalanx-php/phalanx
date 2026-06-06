<?php

declare(strict_types=1);

namespace Phalanx\Http\Testing;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use Phalanx\Testing\Attribute\Lens as LensAttribute;
use Phalanx\Testing\Lens as LensContract;
use Phalanx\Testing\TestApp;
use Psr\Http\Message\ServerRequestInterface;

/**
 * HTTP test lens for a Http application.
 *
 * Drives ServerRequest dispatch through Application::dispatch() and
 * returns a TestResponse with userland-familiar assertions. Carries fluent
 * per-test state (acting identity, default headers) that reset() clears
 * between tests.
 *
 * The lens reaches into the Application registered as a primary app
 * via TestApp::withPrimary(). Booting:
 *
 *     $http = Server::starting($context)->routes($routes)->build();
 *     $app  = $this->testApp($context, new TestableBundle())->withPrimary($http);
 *     $app->http->postJson('/api/orders', ['sku' => 'WIDGET'])->assertCreated();
 */
#[LensAttribute(
    accessor: 'http',
    returns: self::class,
    factory: \Phalanx\Http\Testing\LensFactory::class,
    requires: [\Phalanx\Http\Application::class],
)]
final class Lens implements LensContract
{
    /** @var array<string, string> */
    private array $defaultHeaders = [];

    private mixed $actingAs = null;

    public function __construct(
        /** @phpstan-ignore property.onlyWritten (GC pin — keeps TestApp alive while lens exists) */
        private readonly TestApp $app,
        private readonly \Phalanx\Http\Application $http,
    ) {
    }

    /**
     * Attach an identity to subsequent requests via the standard
     * `'identity'` request attribute. The value is opaque to Lens —
     * userland authentication middleware reads it.
     */
    public function actingAs(mixed $identity): self
    {
        $this->actingAs = $identity;

        return $this;
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        $this->defaultHeaders = [...$this->defaultHeaders, ...$headers];

        return $this;
    }

    /** @param array<string, string> $headers */
    public function get(string $path, array $headers = []): TestResponse
    {
        return $this->dispatch('GET', $path, json: null, headers: $headers);
    }

    /** @param array<string, string> $headers */
    public function head(string $path, array $headers = []): TestResponse
    {
        return $this->dispatch('HEAD', $path, json: null, headers: $headers);
    }

    /** @param array<string, string> $headers */
    public function getJson(string $path, array $headers = []): TestResponse
    {
        return $this->dispatch('GET', $path, json: null, headers: $headers + ['Accept' => 'application/json']);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    public function postJson(string $path, array $body = [], array $headers = []): TestResponse
    {
        return $this->dispatch('POST', $path, json: $body, headers: $headers);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    public function putJson(string $path, array $body = [], array $headers = []): TestResponse
    {
        return $this->dispatch('PUT', $path, json: $body, headers: $headers);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    public function patchJson(string $path, array $body = [], array $headers = []): TestResponse
    {
        return $this->dispatch('PATCH', $path, json: $body, headers: $headers);
    }

    /** @param array<string, string> $headers */
    public function delete(string $path, array $headers = []): TestResponse
    {
        return $this->dispatch('DELETE', $path, json: null, headers: $headers);
    }

    /**
     * Dispatch a fully constructed PSR-7 ServerRequest. Acting identity and
     * default headers are still applied unless the supplied request already
     * carries them.
     */
    public function send(ServerRequestInterface $request): TestResponse
    {
        $response = $this->http->dispatch($this->applyDefaults($request));

        return new TestResponse($response);
    }

    public function reset(): void
    {
        $this->defaultHeaders = [];
        $this->actingAs = null;
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string>     $headers
     */
    private function dispatch(string $method, string $path, ?array $json, array $headers): TestResponse
    {
        $merged = $this->defaultHeaders + $headers;
        $request = new ServerRequest($method, $path, $merged);

        if ($json !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody(Utils::streamFor(json_encode($json, JSON_THROW_ON_ERROR)));
        }

        if ($this->actingAs !== null) {
            $request = $request->withAttribute('identity', $this->actingAs);
        }

        return $this->send($request);
    }

    private function applyDefaults(ServerRequestInterface $request): ServerRequestInterface
    {
        foreach ($this->defaultHeaders as $name => $value) {
            if (!$request->hasHeader($name)) {
                $request = $request->withHeader($name, $value);
            }
        }

        if ($this->actingAs !== null && $request->getAttribute('identity') === null) {
            $request = $request->withAttribute('identity', $this->actingAs);
        }

        return $request;
    }
}
