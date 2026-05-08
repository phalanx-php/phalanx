<?php

declare(strict_types=1);

namespace Phalanx\Surreal\Examples\Support;

use Phalanx\Surreal\SurrealLiveAction;
use Phalanx\Surreal\SurrealLiveNotification;

/**
 * Validates that a live-query notification matches the expected action and
 * contains the expected signal value somewhere in its result payload.
 */
final class SurrealLiveNotificationChecker
{
    public function __construct(private readonly SurrealValueChecker $valueChecker)
    {
    }

    public function __invoke(
        ?SurrealLiveNotification $notification,
        SurrealLiveAction $action,
        string $signal,
    ): bool {
        return $notification instanceof SurrealLiveNotification
            && $notification->action === $action
            && ($this->valueChecker)($notification->result, $signal);
    }
}
