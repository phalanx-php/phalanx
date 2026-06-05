<?php

declare(strict_types=1);

namespace Phalanx\HttpClient\Tests\Unit;

use Phalanx\HttpClient\Runtime\Identity\HttpClientResourceSid;
use Phalanx\Runtime\Identity\RuntimeResourceId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HttpClientResourceSidTest extends TestCase
{
    #[Test]
    public function outboundHttpRequestResourceIdentityIsStable(): void
    {
        $case = HttpClientResourceSid::OutboundHttpRequest;

        self::assertInstanceOf(RuntimeResourceId::class, $case);
        self::assertSame('OutboundHttpRequest', $case->key());
        self::assertSame('http-client.outbound_http_request', $case->value());
        self::assertSame('http-client.outbound_http_request', $case->value);
    }
}
