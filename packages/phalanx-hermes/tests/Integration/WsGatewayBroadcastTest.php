<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Tests\Integration;

use Closure;
use OpenSwoole\Coroutine;
use Phalanx\Hermes\WsConnection;
use Phalanx\Hermes\WsGateway;
use Phalanx\Hermes\WsMessage;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
final class WsGatewayBroadcastTest extends TestCase
{
    private WsGateway $gateway;

    #[Test]
    public function register_and_count(): void
    {
        $conn1 = $this->createConnection();
        $conn2 = $this->createConnection();

        $this->gateway->register($conn1);
        $this->gateway->register($conn2);

        $this->assertSame(2, $this->gateway->count());
    }

    #[Test]
    public function unregister_removes_connection(): void
    {
        $conn = $this->createConnection();
        $this->gateway->register($conn);
        $this->assertSame(1, $this->gateway->count());

        $this->gateway->unregister($conn);
        $this->assertSame(0, $this->gateway->count());
    }

    #[Test]
    public function broadcast_sends_to_all_connections(): void
    {
        $this->runAsync(function (): void {
            $conn1 = $this->createConnection();
            $conn2 = $this->createConnection();
            $conn3 = $this->createConnection();

            $this->gateway->register($conn1);
            $this->gateway->register($conn2);
            $this->gateway->register($conn3);

            $this->gateway->broadcast(WsMessage::text('hello all'));

            $this->assertOutboundContains($conn1, 'hello all');
            $this->assertOutboundContains($conn2, 'hello all');
            $this->assertOutboundContains($conn3, 'hello all');
        });
    }

    #[Test]
    public function broadcast_with_exclude(): void
    {
        $this->runAsync(function (): void {
            $conn1 = $this->createConnection();
            $conn2 = $this->createConnection();

            $this->gateway->register($conn1);
            $this->gateway->register($conn2);

            $this->gateway->broadcast(WsMessage::text('not for you'), exclude: $conn1);

            $this->assertOutboundEmpty($conn1);
            $this->assertOutboundContains($conn2, 'not for you');
        });
    }

    #[Test]
    public function subscribe_and_publish_to_topic(): void
    {
        $this->runAsync(function (): void {
            $conn1 = $this->createConnection();
            $conn2 = $this->createConnection();
            $conn3 = $this->createConnection();

            $this->gateway->register($conn1);
            $this->gateway->register($conn2);
            $this->gateway->register($conn3);

            $this->gateway->subscribe($conn1, 'room:lobby');
            $this->gateway->subscribe($conn2, 'room:lobby');

            $this->gateway->publish('room:lobby', WsMessage::text('lobby msg'));

            $this->assertOutboundContains($conn1, 'lobby msg');
            $this->assertOutboundContains($conn2, 'lobby msg');
            $this->assertOutboundEmpty($conn3);
        });
    }

    #[Test]
    public function publish_with_exclude(): void
    {
        $this->runAsync(function (): void {
            $conn1 = $this->createConnection();
            $conn2 = $this->createConnection();

            $this->gateway->register($conn1);
            $this->gateway->register($conn2);

            $this->gateway->subscribe($conn1, 'chat');
            $this->gateway->subscribe($conn2, 'chat');

            $this->gateway->publish('chat', WsMessage::text('echo excluded'), exclude: $conn1);

            $this->assertOutboundEmpty($conn1);
            $this->assertOutboundContains($conn2, 'echo excluded');
        });
    }

    #[Test]
    public function unsubscribe_removes_from_topic(): void
    {
        $this->runAsync(function (): void {
            $conn = $this->createConnection();
            $this->gateway->register($conn);
            $this->gateway->subscribe($conn, 'alerts');

            $this->assertSame(1, $this->gateway->topicCount('alerts'));

            $this->gateway->unsubscribe($conn, 'alerts');

            $this->assertSame(0, $this->gateway->topicCount('alerts'));

            $this->gateway->publish('alerts', WsMessage::text('missed'));
            $this->assertOutboundEmpty($conn);
        });
    }

    #[Test]
    public function unregister_cleans_up_topic_subscriptions(): void
    {
        $conn = $this->createConnection();
        $this->gateway->register($conn);
        $this->gateway->subscribe($conn, 'room:a', 'room:b');

        $this->assertSame(1, $this->gateway->topicCount('room:a'));
        $this->assertSame(1, $this->gateway->topicCount('room:b'));

        $this->gateway->unregister($conn);

        $this->assertSame(0, $this->gateway->topicCount('room:a'));
        $this->assertSame(0, $this->gateway->topicCount('room:b'));
    }

    #[Test]
    public function publish_to_empty_topic_is_noop(): void
    {
        $this->gateway->publish('nonexistent', WsMessage::text('void'));
        $this->assertSame(0, $this->gateway->topicCount('nonexistent'));
    }

    #[Test]
    public function closed_connections_are_skipped_during_broadcast(): void
    {
        $this->runAsync(function (): void {
            $conn1 = $this->createConnection();
            $conn2 = $this->createConnection();

            $this->gateway->register($conn1);
            $this->gateway->register($conn2);

            $conn1->close();

            $this->gateway->broadcast(WsMessage::text('after close'));

            $this->assertOutboundContains($conn2, 'after close');
        });
    }

    protected function setUp(): void
    {
        $this->gateway = new WsGateway();
    }

    private function createConnection(): WsConnection
    {
        return new WsConnection(bin2hex(random_bytes(8)));
    }

    /**
     * Wrap a test body that touches Channel ops in a single OpenSwoole
     * coroutine. emit, complete, and consume must share a scheduler; without
     * this wrapping the gateway's broadcast() (which calls outbound->emit)
     * has no scheduler to push into.
     */
    private function runAsync(Closure $body): void
    {
        $caught = null;
        Coroutine::run(static function () use ($body, &$caught): void {
            try {
                $body();
            } catch (Throwable $e) {
                $caught = $e;
            }
        });
        if ($caught !== null) {
            throw $caught;
        }
    }

    /**
     * Caller is already inside Coroutine::run. Drain helpers below assume
     * that and call complete()+consume directly.
     */
    private function assertOutboundContains(WsConnection $conn, string $expected): void
    {
        $msg = $this->drainOneFromOutbound($conn);
        $this->assertNotNull($msg, 'Expected outbound message but channel was empty');
        $this->assertSame($expected, $msg->payload);
    }

    private function assertOutboundEmpty(WsConnection $conn): void
    {
        $conn->outbound->complete();

        /** @var list<WsMessage> $messages */
        $messages = [];
        foreach ($conn->outbound->consume() as $msg) {
            if ($msg instanceof WsMessage && !$msg->isClose) {
                $messages[] = $msg;
            }
        }

        $this->assertEmpty(
            $messages,
            sprintf('Expected empty outbound but found %d message(s)', count($messages)),
        );
    }

    private function drainOneFromOutbound(WsConnection $conn): ?WsMessage
    {
        $conn->outbound->complete();

        foreach ($conn->outbound->consume() as $msg) {
            if ($msg instanceof WsMessage && !$msg->isClose) {
                return $msg;
            }
        }
        return null;
    }
}
