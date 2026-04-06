<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Testing\Probe;

use Phalanx\Testing\Probe\ConcurrencyProbe;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConcurrencyProbeTest extends TestCase
{
    #[Test]
    public function tracks_high_water_mark(): void
    {
        $probe = new ConcurrencyProbe();

        $probe->enter();
        $probe->enter();
        $probe->enter();
        $this->assertSame(3, $probe->maxConcurrent);

        $probe->exit();
        $this->assertSame(3, $probe->maxConcurrent);
        $this->assertSame(2, $probe->current);
    }

    #[Test]
    public function current_reflects_live_count(): void
    {
        $probe = new ConcurrencyProbe();

        $probe->enter();
        $this->assertSame(1, $probe->current);

        $probe->enter();
        $this->assertSame(2, $probe->current);

        $probe->exit();
        $this->assertSame(1, $probe->current);

        $probe->exit();
        $this->assertSame(0, $probe->current);
    }

    #[Test]
    public function reset_clears_state(): void
    {
        $probe = new ConcurrencyProbe();
        $probe->enter();
        $probe->enter();
        $probe->reset();

        $this->assertSame(0, $probe->current);
        $this->assertSame(0, $probe->maxConcurrent);
    }
}
