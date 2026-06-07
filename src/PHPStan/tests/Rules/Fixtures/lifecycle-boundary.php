<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Phalanx\Runtime\Memory\RuntimeMemory;
use Swoole\Table;

final class LifecycleBoundaryFixture
{
    public function bypass(RuntimeMemory $memory): void
    {
        $memory->resources->open('demo.resource');
        $memory->tables->resources->set('demo', []);
        $memory->tables->resourceEvents->set('event', []);
        $memory->tables->counters->incr('counter', 'value');
        new Table(16);
    }
}
