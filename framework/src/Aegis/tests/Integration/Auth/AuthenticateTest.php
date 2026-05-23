<?php

declare(strict_types=1);

namespace Phalanx\Tests\Integration\Auth;

use Phalanx\Auth\AuthContext;
use Phalanx\Auth\Identity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuthenticateTest extends TestCase
{
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
