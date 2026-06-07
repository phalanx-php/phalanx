<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Swoole\Table;

final class LifecycleBoundaryFixture
{
    public function bypass(object $memory): void
    {
        $memory->resources->open('demo.resource');
        $memory->tables->resources->set('demo', []);
        new Table(16);
    }
}
