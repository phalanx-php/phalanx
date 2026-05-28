<?php

declare(strict_types=1);

namespace AgentBridge\Tests\Telemetry;

use AgentBridge\Telemetry\Daemon8TraceListener;
use PHPUnit\Framework\TestCase;

final class Daemon8TraceListenerTest extends TestCase
{
    private static function ingestUrl(): string
    {
        $base = rtrim($GLOBALS['DAEMON8_BASE_URL'] ?? 'http://127.0.0.1:9077', '/');

        return $base . '/ingest';
    }

    public function testTraceDoesNotThrowWhenEndpointUnreachable(): void
    {
        $listener = new Daemon8TraceListener('http://127.0.0.1:1/ingest');

        $listener->trace('EXEC', 'handleTabMessage', 'tab.connect tabId=1', null);

        self::assertTrue(true);
    }

    public function testWireDoesNotThrowWhenEndpointUnreachable(): void
    {
        $listener = new Daemon8TraceListener('http://127.0.0.1:1/ingest');

        $listener->wire('in', 'tab.connect', 1, 'tabId=1');

        self::assertTrue(true);
    }

    public function testEmitDoesNotThrowWhenEndpointUnreachable(): void
    {
        $listener = new Daemon8TraceListener('http://127.0.0.1:1/unreachable');

        // This should not throw -- errors are silently swallowed
        $listener->emit('log', 'test', ['msg' => 'should not throw']);

        // If we reach here, the fire-and-forget pattern works
        self::assertTrue(true);
    }
}
