<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Unit;

use Phalanx\Runtime\Identity\RuntimeResourceId;
use Phalanx\Stoa\Runtime\Identity\StoaResourceSid;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StoaResourceSidTest extends TestCase
{
    #[Test]
    public function allCasesImplementRuntimeResourceIdAndExposeStableValues(): void
    {
        $expected = [
            'HttpRequest' => 'stoa.http_request',
            'HttpServer' => 'stoa.http_server',
            'SseStream' => 'stoa.sse_stream',
            'WsConnection' => 'stoa.ws_connection',
            'UdpListener' => 'stoa.udp_listener',
            'UdpSession' => 'stoa.udp_session',
        ];

        foreach (StoaResourceSid::cases() as $case) {
            self::assertInstanceOf(RuntimeResourceId::class, $case);
            self::assertArrayHasKey($case->name, $expected);
            self::assertSame($case->name, $case->key());
            self::assertSame($expected[$case->name], $case->value());
            self::assertSame($expected[$case->name], $case->value);
        }

        self::assertSame(count($expected), count(StoaResourceSid::cases()));
    }
}
