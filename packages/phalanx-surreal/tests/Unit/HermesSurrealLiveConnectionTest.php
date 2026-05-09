<?php

declare(strict_types=1);

namespace Phalanx\Surreal\Tests\Unit;

use Generator;
use Phalanx\Hermes\WsMessage;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Styx\Channel;
use Phalanx\Surreal\HermesSurrealLiveConnection;
use Phalanx\Surreal\SurrealException;
use Phalanx\Surreal\SurrealLiveAction;
use Phalanx\Surreal\SurrealLiveNotification;
use Phalanx\Surreal\SurrealLiveSocket;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class HermesSurrealLiveConnectionTest extends PhalanxTestCase
{
    #[Test]
    public function requestSendsJsonRpcEnvelopeAndReturnsResult(): void
    {
        $socket = new FakeLiveSocket([
            static fn(array $payload): array => ['id' => $payload['id'], 'result' => 'olympus-live'],
        ]);

        $result = $this->scope->run(
            static function (ExecutionScope $scope) use ($socket): mixed {
                $connection = new HermesSurrealLiveConnection($scope, $socket, 1.0);

                return $connection->request('live', ['event', false]);
            },
            'test.surreal.live-connection-request',
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
            static function (ExecutionScope $scope) use ($socket): ?SurrealLiveNotification {
                $connection = new HermesSurrealLiveConnection($scope, $socket, 1.0);
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
            'test.surreal.live-connection-notification',
        );

        self::assertInstanceOf(SurrealLiveNotification::class, $notification);
        self::assertSame(SurrealLiveAction::Create, $notification->action);
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

        $this->expectException(SurrealException::class);
        $this->expectExceptionMessage('bad live query');

        $this->scope->run(
            static function (ExecutionScope $scope) use ($socket): mixed {
                $connection = new HermesSurrealLiveConnection($scope, $socket, 1.0);

                return $connection->request('live', ['event']);
            },
            'test.surreal.live-connection-error',
        );
    }

    #[Test]
    public function malformedNotificationFailsSubscribedChannel(): void
    {
        $socket = new FakeLiveSocket();

        $this->expectException(SurrealException::class);
        $this->expectExceptionMessage('Surreal live notification action was missing.');

        $this->scope->run(
            static function (ExecutionScope $scope) use ($socket): mixed {
                $connection = new HermesSurrealLiveConnection($scope, $socket, 1.0);
                $channel = new Channel();
                $connection->subscribe('olympus-live', $channel);

                $socket->push(['result' => ['id' => 'olympus-live']]);

                return $channel->next(1.0);
            },
            'test.surreal.live-connection-malformed-notification',
        );
    }

    #[Test]
    public function fullSubscriberChannelFailsWithoutBlockingReadLoop(): void
    {
        $socket = new FakeLiveSocket();

        $this->expectException(SurrealException::class);
        $this->expectExceptionMessage('Surreal live subscription olympus-live buffer is full.');

        $this->scope->run(
            static function (ExecutionScope $scope) use ($socket): mixed {
                $connection = new HermesSurrealLiveConnection($scope, $socket, 1.0);
                $channel = new Channel(1);
                $channel->emit(new SurrealLiveNotification(
                    action: SurrealLiveAction::Create,
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
                self::assertInstanceOf(SurrealLiveNotification::class, $first);
                self::assertSame('event:existing', $first->result['id'] ?? null);

                return $channel->next(1.0);
            },
            'test.surreal.live-connection-full-channel',
        );
    }
}

final class FakeLiveSocket implements SurrealLiveSocket
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
