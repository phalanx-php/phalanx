<?php

declare(strict_types=1);

namespace Phalanx\Tests\Fixtures\Testing;

use Phalanx\Testing\Attribute\TestLens;
use Phalanx\Testing\TestLens as TestLensContract;

#[TestLens(
    accessor: 'fixture',
    returns: self::class,
    factory: FixtureLensFactory::class,
    requires: [],
)]
final class FixtureLens implements TestLensContract
{
    public int $resetCount = 0;

    public string $tag = 'untouched';

    public function reset(): void
    {
        $this->resetCount++;
        $this->tag = 'reset';
    }
}
