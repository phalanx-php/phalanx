<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\System;

use Phalanx\System\HttpResponse;
use PHPUnit\Framework\TestCase;

/**
 * The successful flag follows the standard 2xx range. The header()
 * accessor is case-insensitive — upstream servers (HTTP/2 in
 * particular) lowercase header names, but callers may have built
 * requests with mixed case.
 */
final class HttpResponseTest extends TestCase
{
    public function testSuccessfulIsTrueFor2xx(): void
    {
        self::assertTrue((new HttpResponse(200, [], ''))->successful);
        self::assertTrue((new HttpResponse(204, [], ''))->successful);
        self::assertTrue((new HttpResponse(299, [], ''))->successful);
    }

    public function testSuccessfulIsFalseOutside2xx(): void
    {
        self::assertFalse((new HttpResponse(199, [], ''))->successful);
        self::assertFalse((new HttpResponse(300, [], ''))->successful);
        self::assertFalse((new HttpResponse(404, [], ''))->successful);
        self::assertFalse((new HttpResponse(500, [], ''))->successful);
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $resp = new HttpResponse(200, ['Content-Type' => 'application/json'], '');

        self::assertSame('application/json', $resp->header('content-type'));
        self::assertSame('application/json', $resp->header('Content-Type'));
        self::assertSame('application/json', $resp->header('CONTENT-TYPE'));
    }

    public function testHeaderReturnsNullForMissing(): void
    {
        $resp = new HttpResponse(200, [], '');
        self::assertNull($resp->header('x-missing'));
    }
}
