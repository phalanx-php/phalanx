<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Unit;

use Phalanx\WebSocket\CloseCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WsCloseCodeTest extends TestCase
{
    #[Test]
    public function normalCloseHasValue1000(): void
    {
        $this->assertSame(1000, \Phalanx\WebSocket\CloseCode::Normal->value);
    }

    #[Test]
    public function reservedCodesAreIdentified(): void
    {
        $this->assertTrue(\Phalanx\WebSocket\CloseCode::NoStatusReceived->isReserved());
        $this->assertTrue(\Phalanx\WebSocket\CloseCode::AbnormalClosure->isReserved());
        $this->assertFalse(\Phalanx\WebSocket\CloseCode::Normal->isReserved());
        $this->assertFalse(\Phalanx\WebSocket\CloseCode::GoingAway->isReserved());
    }
}
