<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb\Tests\Unit;

use Generator;
use Phalanx\WebSocket\WsMessage;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Stream\Channel;
use Phalanx\SurrealDb\WebSocketSurrealDbLiveConnection;
use Phalanx\SurrealDb\SurrealDbException;
use Phalanx\SurrealDb\SurrealDbLiveAction;
use Phalanx\SurrealDb\SurrealDbLiveNotification;
use Phalanx\SurrealDb\SurrealDbLiveSocket;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class WebSocketSurrealDbLiveConnectionTest extends PhalanxTestCase
{
    #[Test]
    public function requestSendsJsonRpcEnvelopeAndReturnsResult(): void
    {
        $socket = new FakeLiveSocket([
            static fn(array $payload): array => ['id' => $payload['id'], 'result' => 'olympus-live'],
        ]);

        $result = $this->scope->run(
            static function (ExecutionScope $scope) use ($socket): mixed {
                $connection = new WebSocketSurrealDbLiveConnection($scope, $socket, 1.0);

                return $connection->request('live', ['event', false]);
            },
            'test.surrealdb.live-connection-request',
        );

        self::assertSame('olympus-live', $result);
        self::assertSame('live', $socket->sent[0]['method']);
        self::assertSame(['event', false], $socket->sent[0]['params']);
    }

    #[Test]
    public function notificationDemuxesToSubscribedChannel(): void
    {
        $socket = new FakeLiveSocket();

        $notification = $this->scope->run(
            static function (ExecutionScope $scope) use ($socket): ?SurrealDbLiveNotification {
                $connection = new WebSocketSurrealDbLiveConnection($scope, $socket, 1.0);
                $channel = new Channel();
                $connection->subscribe('olympus-live', $channel);

                $socket->push([
                    'result' => [
                        'action' => 'CREATE',
                        'id' => 'olympus-live',
                        'result' => ['id' => 'event:apollo'],
                    ],
                ]);

                return $channel->next(1.0);
            },
            'test.surrealdb.live-connection-notification',
        );

        self::assertInstanceOf(SurrealDbLiveNotification::class, $notification);
        self::assertSame(SurrealDbLiveAction::Create, $notification->action);
        self::assertSame('event:apollo', $notification->result['id'] ?? null);
    }

    #[Test]
    public function errorEnvelopeFailsRequest(): void
    {
        $socket = new FakeLiveSocket([
            static fn(array $payload): array => [
                'id' => $payload['id'],
                'error' => ['code' => -32000, 'message' => 'bad live query'],
            ],
        ]);

        $this->expectException(SurrealDbException::class);
        $this->expectExceptionMessage('bad live query');

        $this->scope->run(
            static function (ExecutionScope $scope) use ($socket): mixed {
                $connection = new WebSocketSurrealDbLiveConnection($scope, $socket, 1.0);

                return $connection->request('live', ['event']);
            },
            'test.surrealdb.live-connection-error',
        );
    }

    #[Test]
    public function malformedNotificationFailsSubscribedChannel(): void
    {
        $socket = new FakeLiveSocket();

        $this->expectException(SurrealDbException::class);
        $this->expectExceptionMessage('SurrealDb live notification action was missing.');

        $this->scope->run(
            static function (ExecutionScope $scope) use ($socket): mixed {
                $connection = new WebSocketSurrealDbLiveConnection($scope, $socket, 1.0);
                $channel = new Channel();
                $connection->subscribe('olympus-live', $channel);

                $socket->push(['result' => ['id' => 'olympus-live']]);

                return $channel->next(1.0);
            },
            'test.surrealdb.live-connection-malformed-notification',
        );
    }

    #[Test]
    public function fullSubscriberChannelFailsWithoutBlockingReadLoop(): void
    {
        $socket = new FakeLiveSocket();

        $this->expectException(SurrealDbException::class);
        $this->expectExceptionMessage('SurrealDb live subscription olympus-live buffer is full.');

        $this->scope->run(
            static function (ExecutionScope $scope) use ($socket): mixed {
                $connection = new WebSocketSurrealDbLiveConnection($scope, $socket, 1.0);
                $channel = new Channel(1);
                $channel->emit(new SurrealDbLiveNotification(
                    action: SurrealDbLiveAction::Create,
                    queryId: 'olympus-live',
                    result: ['id' => 'event:existing'],
                ));
                $connection->subscribe('olympus-live', $channel);

                $socket->push([
                    'result' => [
                        'action' => 'CREATE',
                        'id' => 'olympus-live',
                        'result' => ['id' => 'event:overflow'],
                    ],
                ]);

                $first = $channel->next(1.0);
                self::assertInstanceOf(SurrealDbLiveNotification::class, $first);
                self::assertSame('event:existing', $first->result['id'] ?? null);

                return $channel->next(1.0);
            },
            'test.surrealdb.live-connection-full-channel',
        );
    }
}

final class FakeLiveSocket implements SurrealDbLiveSocket
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    private Channel $messages;

    /** @param list<callable(array<string, mixed>): array<string, mixed>> $responses */
    public function __construct(
        private array $responses = [],
    ) {
        $this->messages = new Channel();
    }

    public function messages(): Generator
    {
        yield from $this->messages->consume();
    }

    public function sendJson(array $payload): void
    {
        $this->sent[] = $payload;

        $response = array_shift($this->responses);
        if ($response !== null) {
            $this->push($response($payload));
        }
    }

    /** @param array<string, mixed> $payload */
    public function push(array $payload): void
    {
        $this->messages->emit(WsMessage::json($payload));
    }

    public function close(): void
    {
        $this->messages->complete();
    }
}
