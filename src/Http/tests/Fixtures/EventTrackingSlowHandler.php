<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Fixtures;

use Phalanx\Mark\Mark;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;

final class EventTrackingSlowHandler implements Executable
{
    /** @var list<string> */
    public static array $events = [];

    public function __invoke(ExecutionScope $scope): string
    {
        $scope->delay(Mark::ms(300));
        self::$events[] = 'handler:complete';

        return 'done';
    }
}
