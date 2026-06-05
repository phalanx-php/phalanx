<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Unit\Response;

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Application;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Http\ExecutionContext;
use Phalanx\Http\RequestDiagnostics;
use Phalanx\Http\RequestResource;
use Phalanx\Http\ServerConfig;
use Phalanx\Http\QueryParams;
use Phalanx\Http\Response\HtmlErrorResponseRenderer;
use Phalanx\Http\RouteConfig;
use Phalanx\Http\RouteParams;
use Phalanx\Scope\ExecutionLifecycleScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HtmlErrorResponseRendererTest extends TestCase
{
    #[Test]
    public function renderReturnsNullWhenDebugIsOff(): void
    {
        $renderer = new HtmlErrorResponseRenderer(new \Phalanx\Http\ServerConfig(ignitionEnabled: false));
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
        $renderer = new HtmlErrorResponseRenderer(new \Phalanx\Http\ServerConfig(ignitionEnabled: true));
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
            RouteConfig::compile('/fail', 'GET'),
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
