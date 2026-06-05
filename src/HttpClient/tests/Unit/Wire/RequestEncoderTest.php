<?php

declare(strict_types=1);

namespace Phalanx\HttpClient\Tests\Unit\Wire;

use Phalanx\HttpClient\Exception;
use Phalanx\HttpClient\Request;
use Phalanx\HttpClient\Wire\RequestEncoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HttpRequestEncoderTest extends TestCase
{
    #[Test]
    public function getRequestBuildsHostHeaderFromUrl(): void
    {
        $encoded = \Phalanx\HttpClient\Wire\RequestEncoder::encode(\Phalanx\HttpClient\Request::get('http://example.com/path?q=1'));

        self::assertSame('example.com', $encoded['host']);
        self::assertSame(80, $encoded['port']);
        self::assertSame('http', $encoded['scheme']);
        self::assertSame('/path?q=1', $encoded['path']);
        self::assertStringContainsString("GET /path?q=1 HTTP/1.1\r\n", $encoded['request']);
        self::assertStringContainsString("Host: example.com\r\n", $encoded['request']);
        self::assertStringContainsString("Connection: close\r\n", $encoded['request']);
    }

    #[Test]
    public function nonDefaultPortIsEncodedInHostHeader(): void
    {
        $encoded = \Phalanx\HttpClient\Wire\RequestEncoder::encode(\Phalanx\HttpClient\Request::get('http://example.com:8443/'));

        self::assertStringContainsString('Host: example.com:8443', $encoded['request']);
        self::assertSame(8443, $encoded['port']);
    }

    #[Test]
    public function postBodyAddsContentLength(): void
    {
        $encoded = \Phalanx\HttpClient\Wire\RequestEncoder::encode(\Phalanx\HttpClient\Request::post('http://example.com/', 'hello'));

        self::assertStringContainsString("Content-Length: 5\r\n", $encoded['request']);
        self::assertStringEndsWith("\r\nhello", $encoded['request']);
    }

    #[Test]
    public function userAgentInjectedWhenAbsent(): void
    {
        $encoded = \Phalanx\HttpClient\Wire\RequestEncoder::encode(\Phalanx\HttpClient\Request::get('http://example.com/'), userAgent: 'TestAgent/1.0');

        self::assertStringContainsString('User-Agent: TestAgent/1.0', $encoded['request']);
    }

    #[Test]
    public function userAgentNotOverriddenWhenProvided(): void
    {
        $encoded = \Phalanx\HttpClient\Wire\RequestEncoder::encode(
            new \Phalanx\HttpClient\Request('GET', 'http://example.com/', ['User-Agent' => ['Caller/2.0']]),
            userAgent: 'Default/1.0',
        );

        self::assertStringContainsString('User-Agent: Caller/2.0', $encoded['request']);
        self::assertStringNotContainsString('Default/1.0', $encoded['request']);
    }

    #[Test]
    public function httpsSchemeUsesPort443(): void
    {
        $encoded = \Phalanx\HttpClient\Wire\RequestEncoder::encode(\Phalanx\HttpClient\Request::get('https://example.com/'));

        self::assertSame('https', $encoded['scheme']);
        self::assertSame(443, $encoded['port']);
        self::assertStringContainsString('Host: example.com', $encoded['request']);
    }

    #[Test]
    public function rejectsUnsupportedScheme(): void
    {
        $this->expectException(\Phalanx\HttpClient\Exception::class);
        \Phalanx\HttpClient\Wire\RequestEncoder::encode(\Phalanx\HttpClient\Request::get('ftp://example.com/'));
    }

    #[Test]
    public function rejectsMalformedUrl(): void
    {
        $this->expectException(\Phalanx\HttpClient\Exception::class);
        \Phalanx\HttpClient\Wire\RequestEncoder::encode(\Phalanx\HttpClient\Request::get('not a url'));
    }
}
