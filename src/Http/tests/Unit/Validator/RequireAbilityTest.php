<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Unit\Validator;

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Auth\AuthContext;
use Phalanx\Auth\AuthorizationException;
use Phalanx\Auth\Identity;
use Phalanx\Http\AuthExecutionContext;
use Phalanx\Http\ExecutionContext;
use Phalanx\Http\QueryParams;
use Phalanx\Http\RouteConfig;
use Phalanx\Http\RouteParams;
use Phalanx\Http\Validator\RequireAbility;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class RequireAbilityTest extends PhalanxTestCase
{
    #[Test]
    public function returns_empty_when_user_has_ability(): void
    {
        $auth = AuthContext::authenticated(new TestAbilityIdentity(1), null, ['admin', 'write']);
        $scope = new AuthExecutionContext($this->createRequestContext(), $auth);

        $v = new RequireAbility('admin');

        self::assertSame([], $v->validate($scope, null));
    }

    #[Test]
    public function throws_when_user_lacks_ability(): void
    {
        $auth = AuthContext::authenticated(new TestAbilityIdentity(1), null, ['read']);
        $scope = new AuthExecutionContext($this->createRequestContext(), $auth);

        $v = new RequireAbility('admin');

        $this->expectException(AuthorizationException::class);
        $v->validate($scope, null);
    }

    #[Test]
    public function throws_when_scope_is_not_authenticated(): void
    {
        $scope = $this->createRequestContext();

        $v = new RequireAbility('admin');

        $this->expectException(AuthorizationException::class);
        $v->validate($scope, null);
    }

    private function createRequestContext(): ExecutionContext
    {
        $inner = $this->application()->createScope();
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
