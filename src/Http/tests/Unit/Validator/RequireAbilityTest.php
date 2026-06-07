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
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class RequireAbilityTest extends PhalanxTestCase
{
    #[Test]
    public function returns_empty_when_user_has_ability(): void
    {
        $result = $this->scope->run(static function (ExecutionScope $inner): array {
            $auth = AuthContext::authenticated(new TestAbilityIdentity(1), null, ['admin', 'write']);
            $scope = new AuthExecutionContext(self::createRequestContext($inner), $auth);
            $v = new RequireAbility('admin');

            return $v->validate($scope, null);
        });

        self::assertSame([], $result);
    }

    #[Test]
    public function throws_when_user_lacks_ability(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->scope->run(static function (ExecutionScope $inner): void {
            $auth = AuthContext::authenticated(new TestAbilityIdentity(1), null, ['read']);
            $scope = new AuthExecutionContext(self::createRequestContext($inner), $auth);
            $v = new RequireAbility('admin');

            $v->validate($scope, null);
        });
    }

    #[Test]
    public function throws_when_scope_is_not_authenticated(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->scope->run(static function (ExecutionScope $inner): void {
            $scope = self::createRequestContext($inner);
            $v = new RequireAbility('admin');

            $v->validate($scope, null);
        });
    }

    private static function createRequestContext(ExecutionScope $inner): ExecutionContext
    {
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
