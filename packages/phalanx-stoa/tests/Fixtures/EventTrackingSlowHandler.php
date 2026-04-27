<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Fixtures;

use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;

final class EventTrackingSlowHandler implements Executable
{
    /** @var list<string> */
    public static array $events = [];

    public function __invoke(ExecutionScope $scope): string
    {
        $scope->delay(0.3);
        self::$events[] = 'handler:complete';

        return 'done';
    }
}
