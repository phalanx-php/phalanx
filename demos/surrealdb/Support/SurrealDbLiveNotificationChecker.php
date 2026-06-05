<?php

declare(strict_types=1);

namespace Phalanx\Demos\SurrealDb\Support;

use Phalanx\SurrealDb\SurrealDbLiveAction;
use Phalanx\SurrealDb\SurrealDbLiveNotification;

/**
 * Validates that a live-query notification matches the expected action and
 * contains the expected signal value somewhere in its result payload.
 */
final class SurrealDbLiveNotificationChecker
{
    public function __construct(private readonly SurrealDbValueChecker $valueChecker)
    {
    }

    public function __invoke(
        ?SurrealDbLiveNotification $notification,
        SurrealDbLiveAction $action,
        string $signal,
    ): bool {
        return $notification instanceof SurrealDbLiveNotification
            && $notification->action === $action
            && ($this->valueChecker)($notification->result, $signal);
    }
}
