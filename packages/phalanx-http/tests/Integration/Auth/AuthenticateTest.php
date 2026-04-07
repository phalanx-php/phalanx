<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Integration\Auth;

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Application;
use Phalanx\Auth\AuthContext;
use Phalanx\Auth\AuthenticationException;
use Phalanx\Auth\Guard;
use Phalanx\Auth\Identity;
use Phalanx\ExecutionScope;
use Phalanx\Handler\MiddlewareChainLink;
use Phalanx\Http\Auth\Authenticate;
use Phalanx\Http\AuthenticatedExecutionContext;
use Phalanx\Http\AuthenticatedRequestScope;
use Phalanx\Http\ExecutionContext;
use Phalanx\Http\QueryParams;
use Phalanx\Http\RouteConfig;
use Phalanx\Http\RouteParams;
use Phalanx\Task\Executable;
use Phalanx\Tests\Support\TestServiceBundle;
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
        $handler = new class ($capturedScope) implements Executable {
            public function __construct(private mixed &$captured) {}

            public function __invoke(ExecutionScope $scope): mixed
            {
                $this->captured = $scope;
                return 'ok';
            }
        };

        $middleware = new Authenticate($guard);
        $chain = new MiddlewareChainLink($middleware, $handler);

        $requestScope = $this->createRequestScope();
        $result = ($chain)($requestScope);

        $this->assertSame('ok', $result);
        $this->assertInstanceOf(AuthenticatedExecutionContext::class, $capturedScope);
        $this->assertInstanceOf(AuthenticatedRequestScope::class, $capturedScope);
        $this->assertTrue($capturedScope->auth->isAuthenticated);
        $this->assertSame(42, $capturedScope->auth->identity->id);
        $this->assertSame('tok_abc', $capturedScope->auth->token());
    }

    #[Test]
    public function authenticate_throws_when_guard_returns_null(): void
    {
        $guard = new TestGuard(null);
        $handler = new class implements Executable {
            public function __invoke(ExecutionScope $scope): mixed
            {
                return 'should not reach';
            }
        };

        $middleware = new Authenticate($guard);
        $chain = new MiddlewareChainLink($middleware, $handler);

        $this->expectException(AuthenticationException::class);
        ($chain)($this->createRequestScope());
    }

    #[Test]
    public function authenticated_scope_preserves_request_accessors(): void
    {
        $guard = new TestGuard(AuthContext::authenticated(new TestIdentity(1)));

        $capturedScope = null;
        $handler = new class ($capturedScope) implements Executable {
            public function __construct(private mixed &$captured) {}

            public function __invoke(ExecutionScope $scope): mixed
            {
                $this->captured = $scope;
                return null;
            }
        };

        $middleware = new Authenticate($guard);
        $chain = new MiddlewareChainLink($middleware, $handler);
        ($chain)($this->createRequestScope());

        $this->assertSame('GET', $capturedScope->method());
        $this->assertSame('/test', $capturedScope->path());
        $this->assertSame('lobby', $capturedScope->params->get('room'));
    }

    private function createRequestScope(): ExecutionContext
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
    ) {}
}

final class TestGuard implements Guard
{
    public function __construct(
        private readonly ?AuthContext $result,
    ) {}

    public function authenticate(ServerRequestInterface $request): ?AuthContext
    {
        return $this->result;
    }
}
