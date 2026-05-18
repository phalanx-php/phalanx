<?php

declare(strict_types=1);

namespace Phalanx\Athena\Activity;

use Phalanx\Athena\Grant\Store as GrantStore;
use Phalanx\Panoply\Effect\Kind;
use Phalanx\Panoply\Grant;
use Phalanx\Scope\TaskScope;
use Phalanx\Styx\Channel;
use Phalanx\Supervisor\WaitReason;
use Phalanx\Surreal\SurrealLiveAction;
use Phalanx\Surreal\SurrealLiveConnection;
use Phalanx\Surreal\SurrealLiveSubscription;

class GrantMonitor
{
    public function __construct(
        private SurrealLiveConnection $connection,
        private GrantStore $grantStore,
    ) {
    }

    /**
     * Watch for a grant that authorizes the given effect.
     * Suspends the calling fiber until a matching grant appears or scope is cancelled.
     *
     * @param array<string, mixed> $arguments
     */
    public function __invoke(
        TaskScope $scope,
        string $subject,
        Kind $kind,
        array $arguments = [],
    ): Grant {
        $scope->throwIfCancelled();

        $channel      = new Channel(bufferSize: 8);
        $queryId      = (string) $this->connection->request('live', ['athena_grant']);
        $subscription = new SurrealLiveSubscription($queryId, $this->connection, $channel);

        $this->connection->subscribe($queryId, $channel);

        $scope->onDispose(static function () use ($subscription): void {
            $subscription->kill();
        });

        try {
            while (true) {
                $notification = $scope->call(
                    static fn() => $subscription->next(),
                    WaitReason::surreal('live athena_grant'),
                );

                if ($notification === null) {
                    throw new \RuntimeException('Live grant subscription closed unexpectedly.');
                }

                if ($notification->action === SurrealLiveAction::Close) {
                    throw new \RuntimeException('Live grant subscription received CLOSE notification.');
                }

                if (
                    $notification->action === SurrealLiveAction::Create
                    || $notification->action === SurrealLiveAction::Update
                ) {
                    $grant = $this->grantStore->find($scope, $subject, $kind, $arguments);

                    if ($grant !== null) {
                        $subscription->kill();
                        return $grant;
                    }
                }
            }
        } catch (\Throwable $e) {
            $subscription->kill();
            throw $e;
        }
    }
}
