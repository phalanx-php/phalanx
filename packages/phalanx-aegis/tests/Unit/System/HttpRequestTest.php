<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\System;

use Phalanx\System\HttpRequest;
use PHPUnit\Framework\TestCase;

/**
 * HttpRequest is a transparent value object. Builders return fresh
 * instances so request templates can be safely shared across coroutines.
 */
final class HttpRequestTest extends TestCase
{
    public function testGetFactory(): void
    {
        $req = HttpRequest::get('/v1/messages');

        self::assertSame('GET', $req->method);
        self::assertSame('/v1/messages', $req->path);
        self::assertSame('', $req->body);
        self::assertSame([], $req->headers);
    }

    public function testPostFactoryCarriesBodyAndHeaders(): void
    {
        $req = HttpRequest::post('/v1/messages', '{"model":"x"}', ['content-type' => 'application/json']);

        self::assertSame('POST', $req->method);
        self::assertSame('{"model":"x"}', $req->body);
        self::assertSame(['content-type' => 'application/json'], $req->headers);
    }

    public function testWithHeaderProducesNewInstance(): void
    {
        $req = HttpRequest::get('/');
        $authed = $req->withHeader('Authorization', 'Bearer abc');

        self::assertNotSame($req, $authed);
        self::assertSame([], $req->headers);
        self::assertSame(['Authorization' => 'Bearer abc'], $authed->headers);
    }

    public function testWithBodyReplacesPayload(): void
    {
        $req = HttpRequest::post('/echo', 'first');
        $changed = $req->withBody('second');

        self::assertSame('first', $req->body);
        self::assertSame('second', $changed->body);
    }
}
