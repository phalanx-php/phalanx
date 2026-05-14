<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Unit;

use Phalanx\Stoa\MissingRequestCtxValue;
use Phalanx\Stoa\RequestCtx;
use Phalanx\Stoa\RequestCtxKey;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequestCtxTest extends TestCase
{
    #[Test]
    public function storesAndRequiresTypedValuesByKey(): void
    {
        $ctx = new RequestCtx();
        $userId = new RequestCtxUserId(42);

        $ctx->set(RequestCtxUserIdKey::Value, $userId);

        self::assertTrue($ctx->has(RequestCtxUserIdKey::Value));
        self::assertSame($userId, $ctx->get(RequestCtxUserIdKey::Value));
        self::assertSame($userId, $ctx->require(RequestCtxUserIdKey::Value));
    }

    #[Test]
    public function removeAndClearDropValues(): void
    {
        $ctx = new RequestCtx();
        $ctx->set(RequestCtxUserIdKey::Value, new RequestCtxUserId(7));

        $ctx->remove(RequestCtxUserIdKey::Value);
        self::assertFalse($ctx->has(RequestCtxUserIdKey::Value));

        $ctx->set(RequestCtxUserIdKey::Value, new RequestCtxUserId(9));
        $ctx->clear();
        self::assertFalse($ctx->has(RequestCtxUserIdKey::Value));
    }

    #[Test]
    public function requireThrowsWhenValueIsMissing(): void
    {
        $ctx = new RequestCtx();

        $this->expectException(MissingRequestCtxValue::class);
        $this->expectExceptionMessage('Request context value is not set: test.user_id');

        $ctx->require(RequestCtxUserIdKey::Value);
    }
}

/** @implements RequestCtxKey<RequestCtxUserId> */
enum RequestCtxUserIdKey implements RequestCtxKey
{
    case Value;

    public function key(): string
    {
        return 'test.user_id';
    }
}

final readonly class RequestCtxUserId
{
    public function __construct(
        public int $value,
    ) {
    }
}
