<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Tests\Unit\Response;

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Stoa\ExecutionContext;
use Phalanx\Stoa\QueryParams;
use Phalanx\Stoa\Response\IgnitionErrorResponseRenderer;
use Phalanx\Stoa\RouteConfig;
use Phalanx\Stoa\RouteParams;
use Phalanx\Stoa\StoaServerConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IgnitionErrorResponseRendererTest extends TestCase
{
    public function testItReturnsNullWhenDebugIsOff(): void
    {
        $renderer = new IgnitionErrorResponseRenderer(new StoaServerConfig(ignitionEnabled: false));
        $scope = $this->createExecutionContext();

        $response = $renderer->render($scope, new RuntimeException('fail'));

        $this->assertNull($response);
    }

    public function testItRendersHtmlWithBrandingAndLedgerPlaceholder(): void
    {
        $renderer = new IgnitionErrorResponseRenderer(new StoaServerConfig(ignitionEnabled: true));
        $scope = $this->createExecutionContext();

        $response = $renderer->render($scope, new RuntimeException('test error'));

        $this->assertNotNull($response);
        $this->assertSame(500, $response->getStatusCode());

        $html = (string) $response->getBody();
        $this->assertStringContainsString('PHALANX 0.2', $html);
        $this->assertStringContainsString('Diagnostics powered by Phalanx 0.2', $html);
    }

    private function createExecutionContext(array $attributes = []): ExecutionContext
    {
        $inner = $this->createStub(ExecutionScope::class);
        $inner->method('attribute')->willReturnCallback(fn($k, $d = null) => $attributes[$k] ?? $d);
        $inner->method('withAttribute')->willReturn($inner);

        $request = new ServerRequest('GET', '/fail', ['Accept' => 'text/html']);
        return new ExecutionContext(
            $inner,
            $request,
            new RouteParams([]),
            new QueryParams([]),
            RouteConfig::compile('/fail', 'GET')
        );
    }
}
