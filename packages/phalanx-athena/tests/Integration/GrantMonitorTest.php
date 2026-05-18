<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Integration;

use Phalanx\Athena\Activity\GrantMonitor;
use Phalanx\Athena\Grant\Store as GrantStore;
use Phalanx\Panoply\Effect\Kind;
use Phalanx\Panoply\Grant;
use Phalanx\Panoply\Hazard;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\TaskScope;
use Phalanx\Styx\Channel;
use Phalanx\Surreal\SurrealLiveAction;
use Phalanx\Surreal\SurrealLiveConnection;
use Phalanx\Surreal\SurrealLiveNotification;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class GrantMonitorTest extends PhalanxTestCase
{
    #[Test]
    public function createNotificationTriggersFindAndReturnsGrant(): void
    {
        $grant  = self::grant(Kind::FileWrite);
        $result = $this->scope->run(static function (ExecutionScope $scope) use ($grant): Grant {
            $grantStore = new StubGrantStore($grant);
            $connection = new PreloadedConnection(static function (Channel $channel): void {
                $channel->emit(new SurrealLiveNotification(
                    SurrealLiveAction::Create,
                    'live-q-1',
                    ['id' => 'athena_grant:g1'],
                ));
            });

            return (new GrantMonitor($connection, $grantStore))($scope, 'agent_1', Kind::FileWrite);
        });

        self::assertSame($grant, $result);
        $this->scope->expect->runtime()->clean();
    }

    #[Test]
    public function updateNotificationAlsoTriggersFindAndReturnsGrant(): void
    {
        $grant  = self::grant(Kind::FileRead);
        $result = $this->scope->run(static function (ExecutionScope $scope) use ($grant): Grant {
            $grantStore = new StubGrantStore($grant);
            $connection = new PreloadedConnection(static function (Channel $channel): void {
                $channel->emit(new SurrealLiveNotification(
                    SurrealLiveAction::Update,
                    'live-q-1',
                    ['id' => 'athena_grant:g1'],
                ));
            });

            return (new GrantMonitor($connection, $grantStore))($scope, 'agent_1', Kind::FileRead);
        });

        self::assertSame($grant, $result);
        $this->scope->expect->runtime()->clean();
    }

    #[Test]
    public function deleteNotificationSkipsFindAndWaitsForNext(): void
    {
        $grant  = self::grant(Kind::ShellExec);
        $result = $this->scope->run(static function (ExecutionScope $scope) use ($grant): Grant {
            $grantStore = new CountingGrantStore($grant);
            $connection = new PreloadedConnection(static function (Channel $channel): void {
                $channel->emit(new SurrealLiveNotification(
                    SurrealLiveAction::Delete,
                    'live-q-1',
                    null,
                ));
                $channel->emit(new SurrealLiveNotification(
                    SurrealLiveAction::Create,
                    'live-q-1',
                    ['id' => 'athena_grant:g1'],
                ));
            });

            return (new GrantMonitor($connection, $grantStore))($scope, 'agent_1', Kind::ShellExec);
        });

        self::assertSame($grant, $result);
        $this->scope->expect->runtime()->clean();
    }

    #[Test]
    public function closeNotificationThrowsRuntimeException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CLOSE notification');

        $this->scope->run(static function (ExecutionScope $scope): Grant {
            $grantStore = new StubGrantStore(null);
            $connection = new PreloadedConnection(static function (Channel $channel): void {
                $channel->emit(new SurrealLiveNotification(
                    SurrealLiveAction::Close,
                    'live-q-1',
                    null,
                ));
            });

            return (new GrantMonitor($connection, $grantStore))($scope, 'agent_1', Kind::FileWrite);
        });

        $this->scope->expect->runtime()->clean();
    }

    #[Test]
    public function subscriptionIsKilledAfterGrantIsFound(): void
    {
        $grant      = self::grant(Kind::FileWrite);
        $connection = new PreloadedConnection(static function (Channel $channel): void {
            $channel->emit(new SurrealLiveNotification(
                SurrealLiveAction::Create,
                'live-q-1',
                ['id' => 'athena_grant:g1'],
            ));
        });

        $this->scope->run(static function (ExecutionScope $scope) use ($grant, $connection): void {
            $grantStore = new StubGrantStore($grant);
            (new GrantMonitor($connection, $grantStore))($scope, 'agent_1', Kind::FileWrite);
        });

        self::assertTrue($connection->killed);
        $this->scope->expect->runtime()->clean();
    }

    private static function grant(Kind $kind): Grant
    {
        return Grant::of(
            id: 'grant_1',
            subject: 'agent_1',
            allowedEffects: [$kind],
            scope: 'session',
            hazardCeiling: Hazard::Critical,
        );
    }
}

final class PreloadedConnection implements SurrealLiveConnection
{
    public bool $isOpen { get => true; }
    public bool $killed = false;

    public function __construct(
        /** @var \Closure(Channel): void */
        private \Closure $preload
    ) {
    }

    public function request(string $method, array $params = []): mixed
    {
        if ($method === 'live') {
            return 'live-q-1';
        }

        if ($method === 'kill') {
            $this->killed = true;
        }

        return null;
    }

    public function subscribe(string $queryId, Channel $channel): void
    {
        ($this->preload)($channel);
    }

    public function unsubscribe(string $queryId): void
    {
    }

    public function close(): void
    {
    }
}

final class StubGrantStore implements GrantStore
{
    public function __construct(private(set) ?Grant $grant)
    {
    }

    public function find(TaskScope $scope, string $subject, Kind $kind, array $arguments = []): ?Grant
    {
        return $this->grant;
    }

    public function remember(TaskScope $scope, Grant $grant): void
    {
    }

    public function consume(TaskScope $scope, Grant $grant): void
    {
    }

    public function revoke(TaskScope $scope, string $grantId): void
    {
    }
}

final class CountingGrantStore implements GrantStore
{
    public int $findCallCount = 0;

    public function __construct(private(set) ?Grant $grant)
    {
    }

    public function find(TaskScope $scope, string $subject, Kind $kind, array $arguments = []): ?Grant
    {
        $this->findCallCount++;
        return $this->grant;
    }

    public function remember(TaskScope $scope, Grant $grant): void
    {
    }

    public function consume(TaskScope $scope, Grant $grant): void
    {
    }

    public function revoke(TaskScope $scope, string $grantId): void
    {
    }
}
