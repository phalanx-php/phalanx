<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Unit\Auth;

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Auth\AuthContext;
use Phalanx\Auth\AuthenticationException;
use Phalanx\Auth\Guard;
use Phalanx\Auth\Identity;
use Phalanx\WebSocket\Auth\Authenticate;
use Phalanx\WebSocket\AuthWsContext;
use Phalanx\WebSocket\ExecutionContext;
use Phalanx\WebSocket\WsConfig;
use Phalanx\WebSocket\WsConnection;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Http\RouteParams;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class AuthenticateTest extends TestCase
{
    #[Test]
    public function authenticate_passes_authenticated_scope_to_direct_next_closure(): void
    {
        $identity = new TestWsIdentity(42);
        $middleware = new Authenticate(new TestWsGuard(AuthContext::authenticated($identity, 'tok_ws')));
        $scope = new ExecutionContext(
            $this->createStub(ExecutionScope::class),
            new WsConnection('ws-1'),
            new WsConfig(),
            new ServerRequest('GET', '/socket/42'),
            new RouteParams(['id' => '42']),
        );

        $seen = null;
        $result = $middleware($scope, static function (AuthWsContext $ctx) use (&$seen): string {
            $seen = $ctx;

            return $ctx->auth->token() . ':' . $ctx->params->required('id');
        });

        self::assertSame('tok_ws:42', $result);
        self::assertInstanceOf(AuthWsContext::class, $seen);
        self::assertSame(42, $seen->auth->identity->id);
    }

    #[Test]
    public function authenticate_throws_when_guard_returns_null(): void
    {
        $middleware = new Authenticate(new TestWsGuard(null));
        $scope = new ExecutionContext(
            $this->createStub(ExecutionScope::class),
            new WsConnection('ws-1'),
            new WsConfig(),
            new ServerRequest('GET', '/socket'),
            new RouteParams(),
        );

        $this->expectException(AuthenticationException::class);

        $middleware($scope, static fn(): null => null);
    }
}

final readonly class TestWsGuard implements Guard
{
    public function __construct(private ?AuthContext $auth)
    {
    }

    public function authenticate(ServerRequestInterface $request): ?AuthContext
    {
        return $this->auth;
    }
}

final readonly class TestWsIdentity implements Identity
{
    public function __construct(public string|int $id)
    {
    }
}
