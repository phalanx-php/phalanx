<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

use Phalanx\Runtime\Memory\ManagedResourceRegistry;

final class RuntimeSidLiteralInternalFixture
{
    public function sidLiterals(ManagedResourceRegistry $resources): void
    {
        $resources->open('http.request');
        $resources->annotate('resource-1', 'http.route', 'users.show');
    }
}
