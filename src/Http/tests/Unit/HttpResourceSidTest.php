<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Unit;

use Phalanx\Runtime\Identity\RuntimeResourceId;
use Phalanx\Http\Runtime\Identity\HttpResourceSid;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HttpResourceSidTest extends TestCase
{
    #[Test]
    public function allCasesImplementRuntimeResourceIdAndExposeStableValues(): void
    {
        $expected = [
            'HttpRequest' => 'http.http_request',
            'HttpServer' => 'http.http_server',
            'SseStream' => 'http.sse_stream',
            'Connection' => 'http.ws_connection',
            'UdpListener' => 'http.udp_listener',
            'UdpSession' => 'http.udp_session',
        ];

        foreach (HttpResourceSid::cases() as $case) {
            self::assertInstanceOf(RuntimeResourceId::class, $case);
            self::assertArrayHasKey($case->name, $expected);
            self::assertSame($case->name, $case->key());
            self::assertSame($expected[$case->name], $case->value());
            self::assertSame($expected[$case->name], $case->value);
        }

        self::assertSame(count($expected), count(HttpResourceSid::cases()));
    }
}
