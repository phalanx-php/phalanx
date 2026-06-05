<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb\Tests\Unit;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Stream\Channel;
use Phalanx\SurrealDb\SurrealDb;
use Phalanx\SurrealDb\SurrealDbConfig;
use Phalanx\SurrealDb\SurrealDbException;
use Phalanx\SurrealDb\SurrealDbLiveAction;
use Phalanx\SurrealDb\SurrealDbLiveConnection;
use Phalanx\SurrealDb\SurrealDbLiveNotification;
use Phalanx\SurrealDb\SurrealDbLiveTransport;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class SurrealDbLiveTest extends PhalanxTestCase
{
    #[Test]
    public function liveCreatesManagedSubscription(): void
    {
        $connection = new FakeLiveConnection(['olympus-live']);
        $transport = new FakeLiveTransport($connection);

        $subscription = $this->scope->run(
            static function (ExecutionScope $scope) use ($transport): mixed {
                $surrealdb = new SurrealDb(
                    new SurrealDbConfig(namespace: 'olympus', database: 'pantheon'),
                    new FakeSurrealDbTransport([]),
                    $scope,
                    liveTransport: $transport,
                );

                return $surrealdb->live('event', diff: true);
            },
            'test.surrealdb.live',
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
                $surrealdb = new SurrealDb(
                    new SurrealDbConfig(namespace: 'olympus', database: 'pantheon'),
                    new FakeSurrealDbTransport([]),
                    $scope,
                    liveTransport: $transport,
                );

                $surrealdb->let('topic', 'strategy');

                return $surrealdb->liveQuery($query, ['source' => 'oracle']);
            },
            'test.surrealdb.live-query',
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
                $surrealdb = new SurrealDb(
                    new SurrealDbConfig(namespace: 'olympus', database: 'pantheon'),
                    new FakeSurrealDbTransport([]),
                    $scope,
                    liveTransport: $transport,
                );

                $subscription = $surrealdb->live('event');
                $connection->emit('olympus-live', new SurrealDbLiveNotification(
                    action: SurrealDbLiveAction::Create,
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
            'test.surrealdb.live-subscription',
        );

        self::assertSame(SurrealDbLiveAction::Create, $result['action']);
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

        $this->expectException(SurrealDbException::class);
        $this->expectExceptionMessage('Cannot call let while SurrealDb live subscriptions are open.');

        $this->scope->run(
            static function (ExecutionScope $scope) use ($transport): void {
                $surrealdb = new SurrealDb(
                    new SurrealDbConfig(namespace: 'olympus', database: 'pantheon'),
                    new FakeSurrealDbTransport([]),
                    $scope,
                    liveTransport: $transport,
                );

                $surrealdb->live('event');
                $surrealdb->let('topic', 'strategy');
            },
            'test.surrealdb.live-state-guard',
        );
    }

    #[Test]
    public function withDatabaseFailsWhileLiveSubscriptionsAreOpen(): void
    {
        $connection = new FakeLiveConnection(['olympus-live']);
        $transport = new FakeLiveTransport($connection);

        $this->expectException(SurrealDbException::class);
        $this->expectExceptionMessage('Cannot call withDatabase while SurrealDb live subscriptions are open.');

        $this->scope->run(
            static function (ExecutionScope $scope) use ($transport): void {
                $surrealdb = new SurrealDb(
                    new SurrealDbConfig(namespace: 'olympus', database: 'pantheon'),
                    new FakeSurrealDbTransport([]),
                    $scope,
                    liveTransport: $transport,
                );

                $surrealdb->live('event');
                $surrealdb->withDatabase('olympus', 'archive');
            },
            'test.surrealdb.live-database-guard',
        );
    }
}

final class FakeLiveTransport implements SurrealDbLiveTransport
{
    public function __construct(
        public FakeLiveConnection $connection,
    ) {
    }

    public function open(ExecutionScope $scope, SurrealDbConfig $config, ?string $token): SurrealDbLiveConnection
    {
        return $this->connection;
    }
}

final class FakeLiveConnection implements SurrealDbLiveConnection
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

    public function emit(string $queryId, SurrealDbLiveNotification $notification): void
    {
        $this->subscriptions[$queryId]->emit($notification);
    }
}
