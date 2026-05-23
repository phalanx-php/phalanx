<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Fixtures\Routes;

use Phalanx\Task\Scopeable;

/**
 * Zero-parameter handler. Verifies that InputHydrator and the HTTP invoker
 * handle handlers with no parameters -- not even a scope parameter.
 */
final class HealthCheck implements Scopeable
{
    /** @return array{status: string} */
    public function __invoke(): array
    {
        return ['status' => 'ok'];
    }
}
