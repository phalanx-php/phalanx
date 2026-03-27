<?php

declare(strict_types=1);

namespace Phalanx\Tests\Integration\Auth;

use Phalanx\Application;
use Phalanx\Auth\AuthContext;
use Phalanx\Auth\Authenticate;
use Phalanx\Auth\AuthenticationException;
use Phalanx\Auth\Guard;
use Phalanx\Auth\Identity;
use Phalanx\Handler\MiddlewareChainLink;
use Phalanx\Task\Executable;
use Phalanx\ExecutionScope;
use Phalanx\Tests\Support\TestServiceBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

final class AuthenticateTest extends TestCase
{
    #[Test]
    public function authenticate_passes_auth_context_to_next_handler(): void
    {
        $identity = new TestIdentity(42);
        $guard = new TestGuard(AuthContext::authenticated($identity, 'tok_abc'));

        $capturedAuth = null;
        $handler = new class ($capturedAuth) implements Executable {
            public function __construct(private mixed &$captured)
            {
            }

            public function __invoke(ExecutionScope $scope): mixed
            {
                $this->captured = $scope->attribute('auth');
                return 'ok';
            }
        };

        $middleware = new Authenticate($guard);
        $chain = new MiddlewareChainLink($middleware, $handler);

        $scope = $this->createScope();
        $scope = $scope->withAttribute('request', $this->createRequest());

        $result = $scope->execute($chain);

        $this->assertSame('ok', $result);
        $this->assertInstanceOf(AuthContext::class, $capturedAuth);
        $this->assertTrue($capturedAuth->isAuthenticated);
        $this->assertSame(42, $capturedAuth->identity->id);
        $this->assertSame('tok_abc', $capturedAuth->token());
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

        $scope = $this->createScope();
        $scope = $scope->withAttribute('request', $this->createRequest());

        $this->expectException(AuthenticationException::class);
        $scope->execute($chain);
    }

    #[Test]
    public function authenticate_throws_when_no_request(): void
    {
        $guard = new TestGuard(AuthContext::guest());
        $handler = new class implements Executable {
            public function __invoke(ExecutionScope $scope): mixed
            {
                return 'should not reach';
            }
        };

        $middleware = new Authenticate($guard);
        $chain = new MiddlewareChainLink($middleware, $handler);

        $scope = $this->createScope();

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('No request available');
        $scope->execute($chain);
    }

    #[Test]
    public function auth_context_abilities(): void
    {
        $auth = AuthContext::authenticated(new TestIdentity(1), null, ['admin', 'write']);

        $this->assertTrue($auth->can('admin'));
        $this->assertTrue($auth->can('write'));
        $this->assertFalse($auth->can('delete'));
    }

    #[Test]
    public function guest_context_is_not_authenticated(): void
    {
        $auth = AuthContext::guest();

        $this->assertFalse($auth->isAuthenticated);
        $this->assertNull($auth->identity);
        $this->assertNull($auth->token());
        $this->assertFalse($auth->can('anything'));
    }

    private function createScope(): ExecutionScope
    {
        $bundle = TestServiceBundle::create();
        $app = Application::starting()->providers($bundle)->compile();

        return $app->createScope();
    }

    private function createRequest(): ServerRequestInterface
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/test');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getUri')->willReturn($uri);
        $request->method('getHeaderLine')->willReturn('');

        return $request;
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

    public function resolve(ServerRequestInterface $request): ?AuthContext
    {
        return $this->result;
    }
}
