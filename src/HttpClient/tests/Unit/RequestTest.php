<?php

declare(strict_types=1);

namespace Phalanx\HttpClient\Tests\Unit;

use Phalanx\HttpClient\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    #[Test]
    public function factoriesCreateUrlBasedRequests(): void
    {
        $get = \Phalanx\HttpClient\Request::get('https://example.com/events', ['accept' => ['text/event-stream']]);

        self::assertSame('GET', $get->method);
        self::assertSame('https://example.com/events', $get->url);
        self::assertSame(['text/event-stream'], $get->headers['accept']);
        self::assertNull($get->body);

        $post = \Phalanx\HttpClient\Request::post('https://example.com/messages', '{"ok":true}', [
            'content-type' => ['application/json'],
        ]);

        self::assertSame('POST', $post->method);
        self::assertSame('https://example.com/messages', $post->url);
        self::assertSame('{"ok":true}', $post->body);
        self::assertSame(['application/json'], $post->headers['content-type']);
    }
}
