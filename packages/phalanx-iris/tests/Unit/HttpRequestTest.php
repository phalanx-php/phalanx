<?php

declare(strict_types=1);

namespace Phalanx\Iris\Tests\Unit;

use Phalanx\Iris\HttpRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HttpRequestTest extends TestCase
{
    #[Test]
    public function factoriesCreateUrlBasedRequests(): void
    {
        $get = HttpRequest::get('https://example.com/events', ['accept' => ['text/event-stream']]);

        self::assertSame('GET', $get->method);
        self::assertSame('https://example.com/events', $get->url);
        self::assertSame(['text/event-stream'], $get->headers['accept']);
        self::assertNull($get->body);

        $post = HttpRequest::post('https://example.com/messages', '{"ok":true}', [
            'content-type' => ['application/json'],
        ]);

        self::assertSame('POST', $post->method);
        self::assertSame('https://example.com/messages', $post->url);
        self::assertSame('{"ok":true}', $post->body);
        self::assertSame(['application/json'], $post->headers['content-type']);
    }
}
