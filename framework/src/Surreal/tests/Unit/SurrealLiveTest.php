<?php

declare(strict_types=1);

namespace Phalanx\Surreal\Tests\Unit;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Styx\Channel;
use Phalanx\Surreal\Surreal;
use Phalanx\Surreal\SurrealConfig;
use Phalanx\Surreal\SurrealException;
use Phalanx\Surreal\SurrealLiveAction;
use Phalanx\Surreal\SurrealLiveConnection;
use Phalanx\Surreal\SurrealLiveNotification;
use Phalanx\Surreal\SurrealLiveTransport;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class SurrealLiveTest extends PhalanxTestCase
{
    #[Test]
    public function liveCreatesManagedSubscription(): void
    {
        $connection = new FakeLiveConnection(['olympus-live']);
        $transport = new FakeLiveTransport($connection);

        $subscription = $this->scope->run(
            static function (ExecutionScope $scope) use ($transport): mixed {
                $surreal = new Surreal(
                    new SurrealConfig(namespace: 'olympus', database: 'pantheon'),
                    new FakeSurrealTransport([]),
                    $scope,
                    liveTransport: $transport,
                );

                return $surreal->live('event', diff: true);
            },
            'test.surreal.live',
        );

        self::assertSame('olympus-live', $subscription->id());
        self::assertSame(['live'], array_column($connection->requests, 'method'));
        self::assertSame(['event', true], $connection->requests[0]['params']);
        self::assertArrayHasKey('olympus-live', $connection->subscriptions);
    }

    #[Test]
    public function liveQueryMergesLocalAndCallParameters(): void
    {
        $connection = new FakeLiveConnection([
            [['status' => 'OK', 'result' => 'olympus-live']],
        ]);
        $transport = new FakeLiveTransport($connection);
        $query = 'LIVE SELECT * FROM event WHERE topic = $topic AND source = $source';

        $subscription = $this->scope->run(
            static function (ExecutionScope $scope) use ($transport, $query): mixed {
                $surreal = new Surreal(
                    new SurrealConfig(namespace: 'olympus', database: 'pantheon'),
                    new FakeSurrealTransport([]),
                    $scope,
                    liveTransport: $transport,
                );

                $surreal->let('topic', 'strategy');

                return $surreal->liveQuery($query, ['source' => 'oracle']);
            },
            'test.surreal.live-query',
        );

        self::assertSame('olympus-live', $subscription->id());
        self::assertSame('query', $connection->requests[0]['method']);
        self::assertSame(
            [$query, ['topic' => 'strategy', 'source' => 'oracle']],
            $connection->requests[0]['params'],
        );
    }

    #[Test]
    public function subscriptionReceivesNotificationsAndKillsQuery(): void
    {
        $connection = new FakeLiveConnection(['olympus-live', null]);
        $transport = new FakeLiveTransport($connection);

        $result = $this->scope->run(
            static function (ExecutionScope $scope) use ($connection, $transport): array {
                $surreal = new Surreal(
                    new SurrealConfig(namespace: 'olympus', database: 'pantheon'),
                    new FakeSurrealTransport([]),
                    $scope,
                    liveTransport: $transport,
                );

                $subscription = $surreal->live('event');
                $connection->emit('olympus-live', new SurrealLiveNotification(
                    action: SurrealLiveAction::Create,
                    queryId: 'olympus-live',
                    result: ['id' => 'event:apollo'],
                ));
                $notification = $subscription->next(0.1);
                $subscription->kill();

                return [
                    'action' => $notification?->action,
                    'record' => $notification?->result['id'] ?? null,
                    'open' => $subscription->isOpen,
                ];
            },
            'test.surreal.live-subscription',
        );

        self::assertSame(SurrealLiveAction::Create, $result['action']);
        self::assertSame('event:apollo', $result['record']);
        self::assertFalse($result['open']);
        self::assertSame(['live', 'kill'], array_column($connection->requests, 'method'));
        self::assertSame(['olympus-live'], $connection->requests[1]['params']);
    }

    #[Test]
    public function connectionStateMutationFailsWhileLiveSubscriptionsAreOpen(): void
    {
        $connection = new FakeLiveConnection(['olympus-live']);
        $transport = new FakeLiveTransport($connection);

        $this->expectException(SurrealException::class);
        $this->expectExceptionMessage('Cannot call let while Surreal live subscriptions are open.');

        $this->scope->run(
            static function (ExecutionScope $scope) use ($transport): void {
                $surreal = new Surreal(
                    new SurrealConfig(namespace: 'olympus', database: 'pantheon'),
                    new FakeSurrealTransport([]),
                    $scope,
                    liveTransport: $transport,
                );

                $surreal->live('event');
                $surreal->let('topic', 'strategy');
            },
            'test.surreal.live-state-guard',
        );
    }

    #[Test]
    public function withDatabaseFailsWhileLiveSubscriptionsAreOpen(): void
    {
        $connection = new FakeLiveConnection(['olympus-live']);
        $transport = new FakeLiveTransport($connection);

        $this->expectException(SurrealException::class);
        $this->expectExceptionMessage('Cannot call withDatabase while Surreal live subscriptions are open.');

        $this->scope->run(
            static function (ExecutionScope $scope) use ($transport): void {
                $surreal = new Surreal(
                    new SurrealConfig(namespace: 'olympus', database: 'pantheon'),
                    new FakeSurrealTransport([]),
                    $scope,
                    liveTransport: $transport,
                );

                $surreal->live('event');
                $surreal->withDatabase('olympus', 'archive');
            },
            'test.surreal.live-database-guard',
        );
    }
}

final class FakeLiveTransport implements SurrealLiveTransport
{
    public function __construct(
        public FakeLiveConnection $connection,
    ) {
    }

    public function open(ExecutionScope $scope, SurrealConfig $config, ?string $token): SurrealLiveConnection
    {
        return $this->connection;
    }
}

final class FakeLiveConnection implements SurrealLiveConnection
{
    public bool $isOpen {
        get => $this->open;
    }

    /** @var list<array{method: string, params: list<mixed>}> */
    public array $requests = [];

    /** @var array<string, Channel> */
    public array $subscriptions = [];

    private bool $open = true;

    /** @param list<mixed> $responses */
    public function __construct(
        private array $responses,
    ) {
    }

    public function request(string $method, array $params = []): mixed
    {
        $this->requests[] = [
            'method' => $method,
            'params' => $params,
        ];

        return array_shift($this->responses);
    }

    public function subscribe(string $queryId, Channel $channel): void
    {
        $this->subscriptions[$queryId] = $channel;
    }

    public function unsubscribe(string $queryId): void
    {
        unset($this->subscriptions[$queryId]);
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function emit(string $queryId, SurrealLiveNotification $notification): void
    {
        $this->subscriptions[$queryId]->emit($notification);
    }
}
