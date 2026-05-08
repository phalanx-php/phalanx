<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Tests\Unit;

use Phalanx\Hermes\WsCloseCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WsCloseCodeTest extends TestCase
{
    #[Test]
    public function normalCloseHasValue1000(): void
    {
        $this->assertSame(1000, WsCloseCode::Normal->value);
    }

    #[Test]
    public function allCodesAreValid(): void
    {
        foreach (WsCloseCode::cases() as $code) {
            $this->assertTrue($code->isValid(), "Code {$code->value} should be valid");
        }
    }

    #[Test]
    public function reservedCodesAreIdentified(): void
    {
        $this->assertTrue(WsCloseCode::NoStatusReceived->isReserved());
        $this->assertTrue(WsCloseCode::AbnormalClosure->isReserved());
        $this->assertFalse(WsCloseCode::Normal->isReserved());
        $this->assertFalse(WsCloseCode::GoingAway->isReserved());
    }

    #[Test]
    public function tryFromReturnsNullForUnknownCode(): void
    {
        $this->assertNull(WsCloseCode::tryFrom(9999));
    }

    #[Test]
    public function tryFromReturnsCaseForKnownCode(): void
    {
        $this->assertSame(WsCloseCode::Normal, WsCloseCode::tryFrom(1000));
        $this->assertSame(WsCloseCode::InternalError, WsCloseCode::tryFrom(1011));
    }
}
