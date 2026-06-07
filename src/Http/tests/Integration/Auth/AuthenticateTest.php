<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Integration\Auth;

use Closure;
use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Auth\AuthContext;
use Phalanx\Auth\AuthenticationException;
use Phalanx\Auth\Guard;
use Phalanx\Auth\Identity;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Http\Auth\Authenticate;
use Phalanx\Http\AuthExecutionContext;
use Phalanx\Http\AuthRequestContext;
use Phalanx\Http\ExecutionContext;
use Phalanx\Http\QueryParams;
use Phalanx\Http\RouteConfig;
use Phalanx\Http\RouteParams;
use Phalanx\Runtime\Tests\Support\TestServiceBundle;
use PHPUnit\Framework\Attributes\Test;
use Phalanx\Testing\PhalanxTestCase;
use Psr\Http\Message\ServerRequestInterface;

final class AuthenticateTest extends PhalanxTestCase
{
    #[Test]
    public function authenticate_wraps_scope_in_authenticated_context(): void
    {
        $identity = new TestIdentity(42);
        $guard = new TestGuard(AuthContext::authenticated($identity, 'tok_abc'));

        $middleware = new Authenticate($guard);

        $result = $this->withRequestContext(
            static fn(ExecutionContext $requestCtx): string => $middleware(
                $requestCtx,
                static function (ExecutionScope $scope): string {
                    self::assertInstanceOf(AuthExecutionContext::class, $scope);
                    self::assertInstanceOf(AuthRequestContext::class, $scope);
                    self::assertTrue($scope->auth->isAuthenticated);
                    self::assertNotNull($scope->auth->identity);
                    self::assertSame(42, $scope->auth->identity->id);
                    self::assertSame('tok_abc', $scope->auth->token());

                    return 'ok';
                },
            ),
        );

        self::assertSame('ok', $result);
    }

    #[Test]
    public function authenticate_throws_when_guard_returns_null(): void
    {
        $guard = new TestGuard(null);
        $middleware = new Authenticate($guard);

        $this->expectException(AuthenticationException::class);
        $this->withRequestContext(
            static fn(ExecutionContext $requestCtx): string => $middleware(
                $requestCtx,
                static fn(): string => 'should not reach',
            ),
        );
    }

    #[Test]
    public function authenticated_scope_preserves_request_accessors(): void
    {
        $guard = new TestGuard(AuthContext::authenticated(new TestIdentity(1)));

        $middleware = new Authenticate($guard);
        $this->withRequestContext(static fn(ExecutionContext $requestCtx): mixed => $middleware(
            $requestCtx,
            static function (ExecutionScope $scope): null {
                self::assertSame('GET', $scope->method());
                self::assertSame('/test', $scope->path());
                self::assertSame('lobby', $scope->params->get('room'));

                return null;
            },
        ));
    }

    /** @param Closure(ExecutionContext): mixed $test */
    private function withRequestContext(Closure $test): mixed
    {
        $bundle = TestServiceBundle::create();
        $app = $this->testApp([], $bundle)->application;
        $request = new ServerRequest('GET', '/test', ['Authorization' => 'Bearer tok_abc']);

        return $app->scoped(static function (ExecutionScope $inner) use ($request, $test): mixed {
            return $test(new ExecutionContext(
                $inner,
                $request,
                new RouteParams(['room' => 'lobby']),
                new QueryParams($request->getQueryParams()),
                new RouteConfig(),
            ));
        });
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
