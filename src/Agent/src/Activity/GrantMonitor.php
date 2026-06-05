<?php

declare(strict_types=1);

namespace Phalanx\Agent\Activity;

use Phalanx\Agent\Grant\Store as GrantStore;
use Phalanx\AiProviders\Effect\Kind;
use Phalanx\AiProviders\Grant;
use Phalanx\Scope\TaskScope;
use Phalanx\Stream\Channel;
use Phalanx\Supervisor\WaitReason;

class GrantMonitor
{
    public function __construct(
        private \Phalanx\SurrealDb\Live\Connection $connection,
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

        $channel = new Channel(bufferSize: 8);
        $queryId = (string) $this->connection->request('live', ['agent_grant']);
        $subscription = new \Phalanx\SurrealDb\Live\Subscription($queryId, $this->connection, $channel);

        $this->connection->subscribe($queryId, $channel);

        $scope->onDispose(static function () use ($subscription): void {
            $subscription->kill();
        });

        try {
            while (true) {
                $notification = $scope->call(
                    static fn() => $subscription->next(),
                    WaitReason::surrealdb('live agent_grant'),
                );

                if ($notification === null) {
                    throw new \RuntimeException('Live grant subscription closed unexpectedly.');
                }

                if ($notification->action === \Phalanx\SurrealDb\Live\Action::Close) {
                    throw new \RuntimeException('Live grant subscription received CLOSE notification.');
                }

                if (
                    $notification->action === \Phalanx\SurrealDb\Live\Action::Create
                    || $notification->action === \Phalanx\SurrealDb\Live\Action::Update
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
