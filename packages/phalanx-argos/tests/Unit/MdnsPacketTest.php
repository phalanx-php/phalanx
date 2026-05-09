<?php

declare(strict_types=1);

namespace Phalanx\Argos\Tests\Unit;

use Phalanx\Argos\Discovery\MdnsPacket;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class MdnsPacketTest extends TestCase
{
    #[Test]
    public function ptrQueryBuildsOneQuestionPacket(): void
    {
        $packet = MdnsPacket::ptrQuery('_services._dns-sd._udp.local');

        self::assertSame("\0\0\0\0\0\1\0\0\0\0\0\0", substr($packet, 0, 12));
        self::assertStringContainsString("\x09_services\x07_dns-sd\x04_udp\x05local\0\0\x0C\0\x01", $packet);
    }

    #[Test]
    public function ptrAnswersDecodeCompressedOwnerNames(): void
    {
        $question = substr(MdnsPacket::ptrQuery('_services._dns-sd._udp.local'), 12);
        $target = "\x08_airplay\x04_tcp\x05local\0";
        $response = pack('nnnnnn', 0, 0x8400, 1, 1, 0, 0)
            . $question
            . "\xC0\x0C"
            . pack('nnNn', 12, 1, 120, strlen($target))
            . $target;

        self::assertSame([
            [
                'name' => '_services._dns-sd._udp.local',
                'data' => '_airplay._tcp.local',
            ],
        ], MdnsPacket::ptrAnswers($response));
    }

    #[Test]
    public function ptrAnswersRejectTruncatedPackets(): void
    {
        $this->expectException(UnexpectedValueException::class);

        MdnsPacket::ptrAnswers("\0\0");
    }
}
