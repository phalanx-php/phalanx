<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Tests\Unit\Response;

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Application;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Scope\ExecutionLifecycleScope;
use Phalanx\Stoa\ExecutionContext;
use Phalanx\Stoa\QueryParams;
use Phalanx\Stoa\Response\IgnitionErrorResponseRenderer;
use Phalanx\Stoa\RouteConfig;
use Phalanx\Stoa\RouteParams;
use Phalanx\Stoa\StoaRequestDiagnostics;
use Phalanx\Stoa\StoaRequestResource;
use Phalanx\Stoa\StoaServerConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IgnitionErrorResponseRendererTest extends TestCase
{
    public function testItReturnsNullWhenDebugIsOff(): void
    {
        $renderer = new IgnitionErrorResponseRenderer(new StoaServerConfig(ignitionEnabled: false));
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
        $renderer = new IgnitionErrorResponseRenderer(new StoaServerConfig(ignitionEnabled: true));
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
        $resource = StoaRequestResource::open($app->runtime(), $request, $token, ownerScopeId: $inner->scopeId);
        $inner->bindScopedInstance(StoaRequestResource::class, $resource);
        $inner->bindScopedInstance(StoaRequestDiagnostics::class, new StoaRequestDiagnostics());

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
