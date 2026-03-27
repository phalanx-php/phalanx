<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Unit;

use Phalanx\WebSocket\WsCloseCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WsCloseCodeTest extends TestCase
{
    #[Test]
    public function normal_close_has_value_1000(): void
    {
        $this->assertSame(1000, WsCloseCode::Normal->value);
    }

    #[Test]
    public function all_codes_are_valid(): void
    {
        foreach (WsCloseCode::cases() as $code) {
            $this->assertTrue($code->isValid(), "Code {$code->value} should be valid");
        }
    }

    #[Test]
    public function reserved_codes_are_identified(): void
    {
        $this->assertTrue(WsCloseCode::NoStatusReceived->isReserved());
        $this->assertTrue(WsCloseCode::AbnormalClosure->isReserved());
        $this->assertFalse(WsCloseCode::Normal->isReserved());
        $this->assertFalse(WsCloseCode::GoingAway->isReserved());
    }

    #[Test]
    public function try_from_returns_null_for_unknown_code(): void
    {
        $this->assertNull(WsCloseCode::tryFrom(9999));
    }

    #[Test]
    public function try_from_returns_case_for_known_code(): void
    {
        $this->assertSame(WsCloseCode::Normal, WsCloseCode::tryFrom(1000));
        $this->assertSame(WsCloseCode::InternalError, WsCloseCode::tryFrom(1011));
    }
}
