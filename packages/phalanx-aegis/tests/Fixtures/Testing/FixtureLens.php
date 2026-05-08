<?php

declare(strict_types=1);

namespace Phalanx\Tests\Fixtures\Testing;

use Phalanx\Testing\Attribute\Lens;
use Phalanx\Testing\Lens as LensContract;

#[Lens(
    accessor: 'fixture',
    returns: self::class,
    factory: FixtureLensFactory::class,
    requires: [],
)]
final class FixtureLens implements LensContract
{
    public int $resetCount = 0;

    public string $tag = 'untouched';

    public function reset(): void
    {
        $this->resetCount++;
        $this->tag = 'reset';
    }
}
