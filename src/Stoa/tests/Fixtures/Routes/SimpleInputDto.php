<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Tests\Fixtures\Routes;

/**
 * Minimal DTO for testing that validators receive the hydrated input object.
 */
final class SimpleInputDto
{
    public function __construct(
        public readonly string $name = 'test',
    ) {
    }
}
