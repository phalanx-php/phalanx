<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Repl;

use Phalanx\Theatron\Demos\Repl\Slice\LlmRequestEntry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LlmRequestEntryTest extends TestCase
{
    #[Test]
    public function mark_complete_sets_all_fields(): void
    {
        $entry = new LlmRequestEntry(requestId: 'req-0', method: 'POST', path: '/api/chat');

        $completed = $entry->markComplete(200, 1234.5, 42, '{"ok":true}');

        self::assertSame(200, $completed->status);
        self::assertSame(1234.5, $completed->elapsedMs);
        self::assertSame(42, $completed->tokenCount);
        self::assertSame('{"ok":true}', $completed->responseBody);
        self::assertTrue($completed->complete);
        self::assertNull($completed->error);
    }

    #[Test]
    public function mark_complete_is_immutable(): void
    {
        $entry = new LlmRequestEntry(requestId: 'req-0', method: 'POST', path: '/api/chat');

        $entry->markComplete(200, 100.0, 10, 'body');

        self::assertNull($entry->status);
        self::assertFalse($entry->complete);
    }

    #[Test]
    public function mark_error_sets_error_and_elapsed(): void
    {
        $entry = new LlmRequestEntry(requestId: 'req-0', method: 'POST', path: '/api/chat');

        $errored = $entry->markError('connection refused', 50.0);

        self::assertSame('connection refused', $errored->error);
        self::assertSame(50.0, $errored->elapsedMs);
        self::assertTrue($errored->complete);
        self::assertNull($errored->status);
    }

    #[Test]
    public function mark_error_is_immutable(): void
    {
        $entry = new LlmRequestEntry(requestId: 'req-0', method: 'POST', path: '/api/chat');

        $entry->markError('timeout', 100.0);

        self::assertNull($entry->error);
        self::assertFalse($entry->complete);
    }

    #[Test]
    public function constructor_defaults(): void
    {
        $entry = new LlmRequestEntry(requestId: 'req-1', method: 'MOCK', path: '/mock/chat');

        self::assertSame('req-1', $entry->requestId);
        self::assertSame('MOCK', $entry->method);
        self::assertSame('/mock/chat', $entry->path);
        self::assertNull($entry->status);
        self::assertNull($entry->elapsedMs);
        self::assertNull($entry->tokenCount);
        self::assertNull($entry->requestBody);
        self::assertNull($entry->responseBody);
        self::assertSame(0.0, $entry->startTime);
        self::assertFalse($entry->complete);
        self::assertNull($entry->error);
    }

    #[Test]
    public function constructor_with_request_body(): void
    {
        $entry = new LlmRequestEntry(
            requestId: 'req-2',
            method: 'POST',
            path: '/api/chat',
            requestBody: '{"messages":[]}',
            startTime: 1234567890.123,
        );

        self::assertSame('{"messages":[]}', $entry->requestBody);
        self::assertSame(1234567890.123, $entry->startTime);
    }
}
