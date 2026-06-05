<?php

declare(strict_types=1);

namespace Phalanx\Demos\SurrealDb\Support;


/**
 * Validates that a live-query notification matches the expected action and
 * contains the expected signal value somewhere in its result payload.
 */
final class LiveNotificationChecker
{
    public function __construct(private readonly \Phalanx\Demos\SurrealDb\Support\ValueChecker $valueChecker)
    {
    }

    public function __invoke(
        ?\Phalanx\SurrealDb\Live\Notification $notification,
        \Phalanx\SurrealDb\Live\Action $action,
        string $signal,
    ): bool {
        return $notification instanceof \Phalanx\SurrealDb\Live\Notification
            && $notification->action === $action
            && ($this->valueChecker)($notification->result, $signal);
    }
}
