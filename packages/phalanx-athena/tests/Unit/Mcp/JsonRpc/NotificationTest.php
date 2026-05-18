<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit\Mcp\JsonRpc;

use Phalanx\Athena\Mcp\JsonRpc\Notification;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NotificationTest extends TestCase
{
    #[Test]
    public function encodeProducesValidJsonRpc(): void
    {
        $notification = new Notification('notifications/message', ['level' => 'info', 'data' => 'Leonidas']);
        $encoded = $notification->encode();

        self::assertStringEndsWith("\n", $encoded);

        $decoded = json_decode(trim($encoded), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('2.0', $decoded['jsonrpc']);
        self::assertSame('notifications/message', $decoded['method']);
        self::assertSame(['level' => 'info', 'data' => 'Leonidas'], $decoded['params']);
        self::assertArrayNotHasKey('id', $decoded);
    }

    #[Test]
    public function encodeWithEmptyParams(): void
    {
        $notification = new Notification('notifications/cancelled');
        $encoded = $notification->encode();

        $decoded = json_decode(trim($encoded), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('params', $decoded);
        self::assertSame([], $decoded['params']);
    }

    #[Test]
    public function propertiesAreAccessible(): void
    {
        $notification = new Notification('notifications/progress', ['value' => 42]);

        self::assertSame('notifications/progress', $notification->method);
        self::assertSame(['value' => 42], $notification->params);
    }
}
