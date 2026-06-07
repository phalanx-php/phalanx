<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Phalanx\Runtime\Memory\ManagedResourceRegistry;
use Phalanx\Runtime\Memory\RuntimeCounters;
use Phalanx\Runtime\QueryScope;

final class RuntimeSidLiteralFixture
{
    public function sidLiterals(ManagedResourceRegistry $resources, QueryScope $query, RuntimeCounters $counters): void
    {
        $resources->open('http.request');
        $resources->annotate('resource-1', 'http.route', 'users.show');
        $resources->open(type: 'worker.task');
        $resources->recordEvent('resource-1', type: 'worker.started');
        $resources->annotate('resource-1', key: 'worker.queue', value: 'critical');
        $resources->upgrade('resource-1', toType: 'http.websocket');
        $query->all('runtime.scope');
        $counters->incr('runtime.scope.count');
        $counters->get(name: 'runtime.scope.count');

        $resources->open('not_a_namespaced_sid');
    }
}
