<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Integration;

use Closure;
use Phalanx\WebSocket\Connection;
use Phalanx\WebSocket\Gateway;
use Phalanx\WebSocket\Message;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class WsGatewayBroadcastTest extends PhalanxTestCase
{
    private \Phalanx\WebSocket\Gateway $gateway;

    #[Test]
    public function registerAndCount(): void
    {
        $conn1 = self::createConnection();
        $conn2 = self::createConnection();

        $this->gateway->register($conn1);
        $this->gateway->register($conn2);

        $this->assertSame(2, $this->gateway->count());
    }

    #[Test]
    public function unregisterRemovesConnection(): void
    {
        $conn = self::createConnection();
        $this->gateway->register($conn);
        $this->assertSame(1, $this->gateway->count());

        $this->gateway->unregister($conn);
        $this->assertSame(0, $this->gateway->count());
    }

    #[Test]
    public function broadcastSendsToAllConnections(): void
    {
        $gateway = $this->gateway;

        $this->runAsync(static function () use ($gateway): void {
            $conn1 = self::createConnection();
            $conn2 = self::createConnection();
            $conn3 = self::createConnection();

            $gateway->register($conn1);
            $gateway->register($conn2);
            $gateway->register($conn3);

            $gateway->broadcast(\Phalanx\WebSocket\Message::text('hello all'));

            self::assertOutboundContains($conn1, 'hello all');
            self::assertOutboundContains($conn2, 'hello all');
            self::assertOutboundContains($conn3, 'hello all');
        });
    }

    #[Test]
    public function broadcastWithExclude(): void
    {
        $gateway = $this->gateway;

        $this->runAsync(static function () use ($gateway): void {
            $conn1 = self::createConnection();
            $conn2 = self::createConnection();

            $gateway->register($conn1);
            $gateway->register($conn2);

            $gateway->broadcast(\Phalanx\WebSocket\Message::text('not for you'), exclude: $conn1);

            self::assertOutboundEmpty($conn1);
            self::assertOutboundContains($conn2, 'not for you');
        });
    }

    #[Test]
    public function subscribeAndPublishToTopic(): void
    {
        $gateway = $this->gateway;

        $this->runAsync(static function () use ($gateway): void {
            $conn1 = self::createConnection();
            $conn2 = self::createConnection();
            $conn3 = self::createConnection();

            $gateway->register($conn1);
            $gateway->register($conn2);
            $gateway->register($conn3);

            $gateway->subscribe($conn1, 'room:lobby');
            $gateway->subscribe($conn2, 'room:lobby');

            $gateway->publish('room:lobby', \Phalanx\WebSocket\Message::text('lobby msg'));

            self::assertOutboundContains($conn1, 'lobby msg');
            self::assertOutboundContains($conn2, 'lobby msg');
            self::assertOutboundEmpty($conn3);
        });
    }

    #[Test]
    public function publishWithExclude(): void
    {
        $gateway = $this->gateway;

        $this->runAsync(static function () use ($gateway): void {
            $conn1 = self::createConnection();
            $conn2 = self::createConnection();

            $gateway->register($conn1);
            $gateway->register($conn2);

            $gateway->subscribe($conn1, 'chat');
            $gateway->subscribe($conn2, 'chat');

            $gateway->publish('chat', \Phalanx\WebSocket\Message::text('echo excluded'), exclude: $conn1);

            self::assertOutboundEmpty($conn1);
            self::assertOutboundContains($conn2, 'echo excluded');
        });
    }

    #[Test]
    public function unsubscribeRemovesFromTopic(): void
    {
        $gateway = $this->gateway;

        $this->runAsync(static function () use ($gateway): void {
            $conn = self::createConnection();
            $gateway->register($conn);
            $gateway->subscribe($conn, 'alerts');

            self::assertSame(1, $gateway->topicCount('alerts'));

            $gateway->unsubscribe($conn, 'alerts');

            self::assertSame(0, $gateway->topicCount('alerts'));

            $gateway->publish('alerts', \Phalanx\WebSocket\Message::text('missed'));
            self::assertOutboundEmpty($conn);
        });
    }

    #[Test]
    public function unregisterCleansUpTopicSubscriptions(): void
    {
        $conn = self::createConnection();
        $this->gateway->register($conn);
        $this->gateway->subscribe($conn, 'room:a', 'room:b');

        $this->assertSame(1, $this->gateway->topicCount('room:a'));
        $this->assertSame(1, $this->gateway->topicCount('room:b'));

        $this->gateway->unregister($conn);

        $this->assertSame(0, $this->gateway->topicCount('room:a'));
        $this->assertSame(0, $this->gateway->topicCount('room:b'));
    }

    #[Test]
    public function publishToEmptyTopicIsNoop(): void
    {
        $this->gateway->publish('nonexistent', \Phalanx\WebSocket\Message::text('void'));
        $this->assertSame(0, $this->gateway->topicCount('nonexistent'));
    }

    #[Test]
    public function closedConnectionsAreSkippedDuringBroadcast(): void
    {
        $gateway = $this->gateway;

        $this->runAsync(static function () use ($gateway): void {
            $conn1 = self::createConnection();
            $conn2 = self::createConnection();

            $gateway->register($conn1);
            $gateway->register($conn2);

            $conn1->close();

            $gateway->broadcast(\Phalanx\WebSocket\Message::text('after close'));

            self::assertOutboundContains($conn2, 'after close');
        });
    }

    protected function setUp(): void
    {
        $this->gateway = new \Phalanx\WebSocket\Gateway();
    }

    private static function createConnection(): \Phalanx\WebSocket\Connection
    {
        return new \Phalanx\WebSocket\Connection(uniqid('plx_ws_'));
    }

    /**
     * Wrap a test body that touches Channel ops in a managed Phalanx test scope.
     * emit, complete, and consume must share a scheduler; without this wrapping
     * the gateway's broadcast() (which calls outbound->emit) has no scheduler
     * to push into.
     */
    private function runAsync(Closure $body): void
    {
        $this->scope->run(static function () use ($body): void {
            $body();
        });
    }

    /**
     * Caller is already inside a managed Phalanx test scope. Drain helpers
     * below assume that and call complete()+consume directly.
     */
    private static function assertOutboundContains(\Phalanx\WebSocket\Connection $conn, string $expected): void
    {
        $msg = self::drainOneFromOutbound($conn);
        self::assertNotNull($msg, 'Expected outbound message but channel was empty');
        self::assertSame($expected, $msg->payload);
    }

    private static function assertOutboundEmpty(\Phalanx\WebSocket\Connection $conn): void
    {
        $conn->outbound->complete();

        /** @var list<\Phalanx\WebSocket\Message> $messages */
        $messages = [];
        foreach ($conn->outbound->consume() as $msg) {
            if ($msg instanceof \Phalanx\WebSocket\Message && !$msg->isClose) {
                $messages[] = $msg;
            }
        }

        self::assertEmpty(
            $messages,
            sprintf('Expected empty outbound but found %d message(s)', count($messages)),
        );
    }

    private static function drainOneFromOutbound(\Phalanx\WebSocket\Connection $conn): ?\Phalanx\WebSocket\Message
    {
        $conn->outbound->complete();

        foreach ($conn->outbound->consume() as $msg) {
            if ($msg instanceof \Phalanx\WebSocket\Message && !$msg->isClose) {
                return $msg;
            }
        }
        return null;
    }
}
