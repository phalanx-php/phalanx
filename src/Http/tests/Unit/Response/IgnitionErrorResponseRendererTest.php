<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Unit\Response;

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Http\ExecutionContext;
use Phalanx\Http\QueryParams;
use Phalanx\Http\Response\IgnitionErrorResponseRenderer;
use Phalanx\Http\RouteConfig;
use Phalanx\Http\RouteParams;
use Phalanx\Http\RequestDiagnostics;
use Phalanx\Http\RequestResource;
use Phalanx\Http\ServerConfig;
use Phalanx\Scope\ExecutionLifecycleScope;
use Phalanx\Testing\PhalanxTestCase;
use RuntimeException;

final class IgnitionErrorResponseRendererTest extends PhalanxTestCase
{
    public function testItReturnsNullWhenDebugIsOff(): void
    {
        $renderer = new IgnitionErrorResponseRenderer(new ServerConfig(ignitionEnabled: false));
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
        $renderer = new IgnitionErrorResponseRenderer(new ServerConfig(
            ignitionEnabled: true,
            docsUrl: '/docs',
            githubUrl: '/source',
            swooleDocsUrl: '/swoole',
            phpDocsUrl: '/php',
            phpLogoUrl: '/assets/php.svg',
            swooleLogoUrl: '/assets/swoole.png',
            phalanxMarkUrl: '/assets/mark.png',
            prismThemeStylesheetUrl: '/assets/prism.css',
        ));
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
        $this->assertStringContainsString('/docs', $html);
        $this->assertStringContainsString('/source', $html);
        $this->assertStringContainsString('/swoole', $html);
        $this->assertStringContainsString('/php', $html);
        $this->assertStringContainsString('/assets/php.svg', $html);
        $this->assertStringContainsString('/assets/swoole.png', $html);
        $this->assertStringContainsString('/assets/mark.png', $html);
        $this->assertStringContainsString('/assets/prism.css', $html);
    }

    /**
     * @return array{ExecutionContext, \Closure(): void}
     */
    private function createExecutionContextWithRequestResource(): array
    {
        $app = $this->startedApplication();
        $inner = $app->createScope();
        self::assertInstanceOf(ExecutionLifecycleScope::class, $inner);

        $request = new ServerRequest('GET', '/fail', ['Accept' => 'text/html']);
        $token = CancellationToken::create();
        $resource = RequestResource::open($app->runtime(), $request, $token, ownerScopeId: $inner->scopeId);
        $inner->bindScopedInstance(RequestResource::class, $resource);
        $inner->bindScopedInstance(RequestDiagnostics::class, new RequestDiagnostics());

        $scope = new ExecutionContext(
            $inner,
            $request,
            new RouteParams([]),
            new QueryParams([]),
            RouteConfig::compile('/fail', 'GET'),
        );

        return [
            $scope,
            static function () use ($resource, $inner, $token): void {
                $resource->release();
                $inner->dispose();
                $token->cancel();
            },
        ];
    }
}
