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
    public function reservedCodesAreIdentified(): void
    {
        $this->assertTrue(WsCloseCode::NoStatusReceived->isReserved());
        $this->assertTrue(WsCloseCode::AbnormalClosure->isReserved());
        $this->assertFalse(WsCloseCode::Normal->isReserved());
        $this->assertFalse(WsCloseCode::GoingAway->isReserved());
    }
}
