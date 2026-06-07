<?php

declare(strict_types=1);

namespace Phalanx\PHPStan\Tests\Rules\Fixtures;

final class RuntimeSidLiteralFixture
{
    public function sidLiterals(object $resources): void
    {
        $resources->open('http.request');
        $resources->annotate('resource-1', 'http.route', 'users.show');
    }
}
