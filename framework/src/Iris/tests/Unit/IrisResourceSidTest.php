<?php

declare(strict_types=1);

namespace Phalanx\Iris\Tests\Unit;

use Phalanx\Iris\Runtime\Identity\IrisResourceSid;
use Phalanx\Runtime\Identity\RuntimeResourceId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IrisResourceSidTest extends TestCase
{
    #[Test]
    public function outboundHttpRequestResourceIdentityIsStable(): void
    {
        $case = IrisResourceSid::OutboundHttpRequest;

        self::assertInstanceOf(RuntimeResourceId::class, $case);
        self::assertSame('OutboundHttpRequest', $case->key());
        self::assertSame('iris.outbound_http_request', $case->value());
        self::assertSame('iris.outbound_http_request', $case->value);
    }
}
