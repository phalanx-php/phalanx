<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Unit\Http\Client\Wire;

use Phalanx\Stoa\Http\Client\HttpClientException;
use Phalanx\Stoa\Http\Client\Wire\HttpResponseDecoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HttpResponseDecoderTest extends TestCase
{
    #[Test]
    public function decodesContentLengthBody(): void
    {
        $payload = "HTTP/1.1 200 OK\r\nContent-Length: 5\r\nContent-Type: text/plain\r\n\r\nhello";

        $response = HttpResponseDecoder::decode($payload);

        self::assertSame(200, $response->status);
        self::assertSame('OK', $response->reasonPhrase);
        self::assertSame('1.1', $response->protocolVersion);
        self::assertSame('hello', $response->body);
        self::assertSame('text/plain', $response->header('Content-Type'));
    }

    #[Test]
    public function decodesChunkedBody(): void
    {
        $payload = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n6\r\n world\r\n0\r\n\r\n";

        $response = HttpResponseDecoder::decode($payload);

        self::assertSame(200, $response->status);
        self::assertSame('hello world', $response->body);
    }

    #[Test]
    public function decodesEmptyChunkedBody(): void
    {
        $payload = "HTTP/1.1 204 No Content\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n";

        $response = HttpResponseDecoder::decode($payload);

        self::assertSame(204, $response->status);
        self::assertSame('', $response->body);
    }

    #[Test]
    public function multipleHeaderValuesPreserveOrder(): void
    {
        $payload = "HTTP/1.1 200 OK\r\nSet-Cookie: a=1\r\nSet-Cookie: b=2\r\nContent-Length: 0\r\n\r\n";

        $response = HttpResponseDecoder::decode($payload);

        self::assertSame(['a=1', 'b=2'], $response->headers['Set-Cookie']);
    }

    #[Test]
    public function rejectsResponseWithoutHeaderBoundary(): void
    {
        $this->expectException(HttpClientException::class);
        HttpResponseDecoder::decode('GET / HTTP/1.1');
    }

    #[Test]
    public function rejectsInvalidStatusLine(): void
    {
        $this->expectException(HttpClientException::class);
        HttpResponseDecoder::decode("nonsense\r\n\r\n");
    }

    #[Test]
    public function rejectsMalformedHeader(): void
    {
        $this->expectException(HttpClientException::class);
        HttpResponseDecoder::decode("HTTP/1.1 200 OK\r\nbroken-header-no-colon\r\n\r\n");
    }

    #[Test]
    public function rejectsMalformedChunkSize(): void
    {
        $this->expectException(HttpClientException::class);
        HttpResponseDecoder::decode("HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\nXYZ\r\nbad\r\n");
    }

    #[Test]
    public function truncatesBodyToContentLength(): void
    {
        $payload = "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello-extra-bytes";

        $response = HttpResponseDecoder::decode($payload);

        self::assertSame('hello', $response->body);
    }
}
