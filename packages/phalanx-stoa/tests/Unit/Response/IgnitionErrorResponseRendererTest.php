<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Tests\Unit\Response;

use PHPUnit\Framework\TestCase;
use Phalanx\Stoa\ExecutionContext;
use Phalanx\Stoa\Response\IgnitionErrorResponseRenderer;
use Phalanx\Stoa\StoaRequestResource;
use Phalanx\Stoa\StoaServerConfig;
use Phalanx\Stoa\Runtime\StoaScopeKey;
use Phalanx\Scope\ExecutionScope;
use RuntimeException;
use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Stoa\RouteParams;
use Phalanx\Stoa\QueryParams;
use Phalanx\Stoa\RouteConfig;

final class IgnitionErrorResponseRendererTest extends TestCase
{
    public function test_it_returns_null_when_debug_is_off(): void
    {
        $renderer = new IgnitionErrorResponseRenderer(new StoaServerConfig(ignitionEnabled: false));
        $scope = $this->createExecutionContext();

        $response = $renderer->render($scope, new RuntimeException('fail'));

        $this->assertNull($response);
    }

    public function test_it_renders_html_with_branding_and_ledger_placeholder(): void
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
        $inner = $this->createMock(ExecutionScope::class);
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
