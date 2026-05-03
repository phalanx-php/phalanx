<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Unit\Validator;

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Application;
use Phalanx\Auth\AuthContext;
use Phalanx\Auth\AuthorizationException;
use Phalanx\Auth\Identity;
use Phalanx\Stoa\AuthExecutionContext;
use Phalanx\Stoa\ExecutionContext;
use Phalanx\Stoa\QueryParams;
use Phalanx\Stoa\RouteConfig;
use Phalanx\Stoa\RouteParams;
use Phalanx\Stoa\Validator\RequireAbility;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequireAbilityTest extends TestCase
{
    private Application $app;

    #[Test]
    public function returns_empty_when_user_has_ability(): void
    {
        $auth = AuthContext::authenticated(new TestAbilityIdentity(1), null, ['admin', 'write']);
        $scope = new AuthExecutionContext($this->createRequestScope(), $auth);

        $v = new RequireAbility('admin');

        $this->assertSame([], $v->validate(null, $scope));
    }

    #[Test]
    public function throws_when_user_lacks_ability(): void
    {
        $auth = AuthContext::authenticated(new TestAbilityIdentity(1), null, ['read']);
        $scope = new AuthExecutionContext($this->createRequestScope(), $auth);

        $v = new RequireAbility('admin');

        $this->expectException(AuthorizationException::class);
        $v->validate(null, $scope);
    }

    #[Test]
    public function throws_when_scope_is_not_authenticated(): void
    {
        $scope = $this->createRequestScope();

        $v = new RequireAbility('admin');

        $this->expectException(AuthorizationException::class);
        $v->validate(null, $scope);
    }

    protected function setUp(): void
    {
        $this->app = Application::starting()->compile();
    }

    protected function tearDown(): void
    {
        $this->app->shutdown();
    }

    private function createRequestScope(): ExecutionContext
    {
        $inner = $this->app->createScope();
        $request = new ServerRequest('GET', '/test');

        return new ExecutionContext(
            $inner,
            $request,
            new RouteParams([]),
            new QueryParams([]),
            new RouteConfig(),
        );
    }
}

final class TestAbilityIdentity implements Identity
{
    public string|int $id {
        get => $this->identityId;
    }

    public function __construct(private readonly string|int $identityId)
    {
    }
}
