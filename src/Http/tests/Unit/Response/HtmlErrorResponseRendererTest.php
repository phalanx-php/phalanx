<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Unit\Response;

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Http\ExecutionContext;
use Phalanx\Http\QueryParams;
use Phalanx\Http\RequestDiagnostics;
use Phalanx\Http\RequestResource;
use Phalanx\Http\Response\HtmlErrorResponseRenderer;
use Phalanx\Http\RouteConfig;
use Phalanx\Http\RouteParams;
use Phalanx\Http\ServerConfig;
use Phalanx\Scope\ExecutionLifecycleScope;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class HtmlErrorResponseRendererTest extends PhalanxTestCase
{
    #[Test]
    public function renderReturnsNullWhenDebugIsOff(): void
    {
        $renderer = new HtmlErrorResponseRenderer(new ServerConfig(ignitionEnabled: false));
        [$scope, $cleanup] = $this->createExecutionContextWithRequestResource();

        try {
            $response = $renderer->render($scope, new RuntimeException('fail'));
        } finally {
            $cleanup();
        }

        self::assertNull($response);
    }

    #[Test]
    public function renderReturnsDebugHtmlWhenDebugIsOn(): void
    {
        $renderer = new HtmlErrorResponseRenderer(new ServerConfig(
            ignitionEnabled: true,
            lucideScriptUrl: '/assets/lucide.js',
            fontStylesheetUrl: '/assets/fonts.css',
            prismThemeStylesheetUrl: '/assets/prism.css',
            prismScriptUrl: '/assets/prism.js',
        ));
        [$scope, $cleanup] = $this->createExecutionContextWithRequestResource();

        try {
            $response = $renderer->render($scope, new RuntimeException('test error'));
        } finally {
            $cleanup();
        }

        self::assertNotNull($response);
        self::assertSame(500, $response->getStatusCode());

        $html = (string) $response->getBody();
        self::assertStringContainsString('PHALANX COORDINATION ENGINE', $html);
        self::assertStringContainsString('test error', $html);
        self::assertStringContainsString('/assets/lucide.js', $html);
        self::assertStringContainsString('/assets/fonts.css', $html);
        self::assertStringContainsString('/assets/prism.css', $html);
        self::assertStringContainsString('/assets/prism.js', $html);
    }

    /**
     * @return array{ExecutionContext, \Closure(): void}
     */
    private function createExecutionContextWithRequestResource(): array
    {
        $app = $this->testApp()->start()->hostForInternalTesting();
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
