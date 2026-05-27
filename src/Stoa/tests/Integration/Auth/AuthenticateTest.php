<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Tests\Integration\Auth;

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Application;
use Phalanx\Auth\AuthContext;
use Phalanx\Auth\AuthenticationException;
use Phalanx\Auth\Guard;
use Phalanx\Auth\Identity;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Stoa\Auth\Authenticate;
use Phalanx\Stoa\AuthExecutionContext;
use Phalanx\Stoa\AuthRequestContext;
use Phalanx\Stoa\ExecutionContext;
use Phalanx\Stoa\QueryParams;
use Phalanx\Stoa\RouteConfig;
use Phalanx\Stoa\RouteParams;
use Phalanx\Aegis\Tests\Support\TestServiceBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class AuthenticateTest extends TestCase
{
    #[Test]
    public function authenticate_wraps_scope_in_authenticated_context(): void
    {
        $identity = new TestIdentity(42);
        $guard = new TestGuard(AuthContext::authenticated($identity, 'tok_abc'));

        $capturedScope = null;
        $middleware = new Authenticate($guard);

        $requestCtx = $this->createRequestContext();
        $result = $middleware(
            $requestCtx,
            static function (ExecutionScope $scope) use (&$capturedScope): string {
                $capturedScope = $scope;
                return 'ok';
            },
        );

        self::assertSame('ok', $result);
        self::assertInstanceOf(AuthExecutionContext::class, $capturedScope);
        self::assertInstanceOf(AuthRequestContext::class, $capturedScope);
        self::assertTrue($capturedScope->auth->isAuthenticated);
        self::assertSame(42, $capturedScope->auth->identity->id);
        self::assertSame('tok_abc', $capturedScope->auth->token());
    }

    #[Test]
    public function authenticate_throws_when_guard_returns_null(): void
    {
        $guard = new TestGuard(null);
        $middleware = new Authenticate($guard);

        $this->expectException(AuthenticationException::class);
        $middleware(
            $this->createRequestContext(),
            static fn(): string => 'should not reach',
        );
    }

    #[Test]
    public function authenticated_scope_preserves_request_accessors(): void
    {
        $guard = new TestGuard(AuthContext::authenticated(new TestIdentity(1)));

        $capturedScope = null;
        $middleware = new Authenticate($guard);
        $middleware(
            $this->createRequestContext(),
            static function (ExecutionScope $scope) use (&$capturedScope): null {
                $capturedScope = $scope;
                return null;
            },
        );

        self::assertSame('GET', $capturedScope->method());
        self::assertSame('/test', $capturedScope->path());
        self::assertSame('lobby', $capturedScope->params->get('room'));
    }

    private function createRequestContext(): ExecutionContext
    {
        $bundle = TestServiceBundle::create();
        $app = Application::starting()->providers($bundle)->compile();
        $inner = $app->createScope();

        $request = new ServerRequest('GET', '/test', ['Authorization' => 'Bearer tok_abc']);

        return new ExecutionContext(
            $inner,
            $request,
            new RouteParams(['room' => 'lobby']),
            new QueryParams($request->getQueryParams()),
            new RouteConfig(),
        );
    }
}

final class TestIdentity implements Identity
{
    public string|int $id {
        get => $this->identityId;
    }

    public function __construct(
        private readonly string|int $identityId,
    ) {
    }
}

final class TestGuard implements Guard
{
    public function __construct(
        private readonly ?AuthContext $result,
    ) {
    }

    public function authenticate(ServerRequestInterface $request): ?AuthContext
    {
        return $this->result;
    }
}
