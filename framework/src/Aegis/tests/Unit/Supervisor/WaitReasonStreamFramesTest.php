<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Supervisor;

use Phalanx\Supervisor\WaitKind;
use Phalanx\Supervisor\WaitReason;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the typed wait reasons that Stoa (and other network producers)
 * emit when parked on streamed write/read paths. Pairs with the
 * isExternalWait() classification: all four are external (cross-network).
 */
final class WaitReasonStreamFramesTest extends TestCase
{
    public function testStreamWriteUsesStreamWriteKind(): void
    {
        $reason = WaitReason::streamWrite('sse-stream');

        self::assertSame(WaitKind::StreamWrite, $reason->kind);
        self::assertSame('sse-stream', $reason->detail);
    }

    public function testStreamWriteIncludesByteCountWhenProvided(): void
    {
        $reason = WaitReason::streamWrite('sse-stream', 4096);

        self::assertSame('sse-stream (4096B)', $reason->detail);
    }

    public function testWsFrameWriteDefaultsToFrameLabel(): void
    {
        $reason = WaitReason::wsFrameWrite();

        self::assertSame(WaitKind::WsFrameWrite, $reason->kind);
        self::assertSame('ws.frame', $reason->detail);
    }

    public function testWsFrameWriteIncludesDomainAndBytes(): void
    {
        $reason = WaitReason::wsFrameWrite('chat-room-7', 128);

        self::assertSame('chat-room-7 (128B)', $reason->detail);
    }

    public function testWsFrameReadCarriesDomain(): void
    {
        $reason = WaitReason::wsFrameRead('chat-room-7');

        self::assertSame(WaitKind::WsFrameRead, $reason->kind);
        self::assertSame('chat-room-7', $reason->detail);
    }

    public function testUdpReceiveFormatsHostPort(): void
    {
        $reason = WaitReason::udpReceive('239.255.255.250', 1900);

        self::assertSame(WaitKind::UdpReceive, $reason->kind);
        self::assertSame('239.255.255.250:1900', $reason->detail);
    }

    public function testUdpReceiveAcceptsBareHost(): void
    {
        $reason = WaitReason::udpReceive('239.255.255.250');

        self::assertSame('239.255.255.250', $reason->detail);
    }

    public function testStartedAtIsRecorded(): void
    {
        $before = microtime(true);
        $reason = WaitReason::streamWrite('any');
        $after = microtime(true);

        self::assertGreaterThanOrEqual($before, $reason->startedAt);
        self::assertLessThanOrEqual($after, $reason->startedAt);
    }
}
