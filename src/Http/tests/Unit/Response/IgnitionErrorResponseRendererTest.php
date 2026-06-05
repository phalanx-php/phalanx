<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Unit\Response;

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Application;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Scope\ExecutionLifecycleScope;
use Phalanx\Http\ExecutionContext;
use Phalanx\Http\QueryParams;
use Phalanx\Http\Response\IgnitionErrorResponseRenderer;
use Phalanx\Http\RouteConfig;
use Phalanx\Http\RouteParams;
use Phalanx\Http\RequestDiagnostics;
use Phalanx\Http\RequestResource;
use Phalanx\Http\ServerConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IgnitionErrorResponseRendererTest extends TestCase
{
    public function testItReturnsNullWhenDebugIsOff(): void
    {
        $renderer = new IgnitionErrorResponseRenderer(new \Phalanx\Http\ServerConfig(ignitionEnabled: false));
        [$scope, $cleanup] = $this->createExecutionContextWithRequestResource();

        try {
            $response = $renderer->render($scope, new RuntimeException('fail'));
        } finally {
            $cleanup();
        }

        $this->assertNull($response);
    }

    public function testItRendersHtmlWithBrandingAndLedgerPlaceholder(): void
    {
        $renderer = new IgnitionErrorResponseRenderer(new \Phalanx\Http\ServerConfig(ignitionEnabled: true));
        [$scope, $cleanup] = $this->createExecutionContextWithRequestResource();

        try {
            $response = $renderer->render($scope, new RuntimeException('test error'));
        } finally {
            $cleanup();
        }

        $this->assertNotNull($response);
        $this->assertSame(500, $response->getStatusCode());

        $html = (string) $response->getBody();
        $this->assertStringContainsString('PHALANX 0.2', $html);
        $this->assertStringContainsString('Diagnostics powered by Phalanx 0.2', $html);
    }

    /**
     * @return array{ExecutionContext, \Closure(): void}
     */
    private function createExecutionContextWithRequestResource(): array
    {
        $app = Application::starting()->compile()->startup();
        $inner = $app->createScope();
        self::assertInstanceOf(ExecutionLifecycleScope::class, $inner);

        $request = new ServerRequest('GET', '/fail', ['Accept' => 'text/html']);
        $token = CancellationToken::create();
        $resource = \Phalanx\Http\RequestResource::open($app->runtime(), $request, $token, ownerScopeId: $inner->scopeId);
        $inner->bindScopedInstance(\Phalanx\Http\RequestResource::class, $resource);
        $inner->bindScopedInstance(\Phalanx\Http\RequestDiagnostics::class, new \Phalanx\Http\RequestDiagnostics());

        $scope = new ExecutionContext(
            $inner,
            $request,
            new RouteParams([]),
            new QueryParams([]),
            RouteConfig::compile('/fail', 'GET')
        );

        return [
            $scope,
            static function () use ($resource, $inner, $token, $app): void {
                $resource->release();
                $inner->dispose();
                $token->cancel();
                $app->shutdown();
            },
        ];
    }
}
