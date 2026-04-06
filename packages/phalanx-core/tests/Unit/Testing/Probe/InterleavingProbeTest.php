<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Testing\Probe;

use Phalanx\Testing\Probe\InterleavingProbe;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;

final class InterleavingProbeTest extends TestCase
{
    #[Test]
    public function checkpoint_records_events(): void
    {
        $probe = new InterleavingProbe();
        $probe->checkpoint('a:start');
        $probe->checkpoint('b:start');

        $this->assertSame(['a:start', 'b:start'], $probe->events);
    }

    #[Test]
    public function assertInterleaved_passes_on_cooperative_execution(): void
    {
        $probe = new InterleavingProbe();
        $probe->checkpoint('a:start');
        $probe->checkpoint('b:start');
        $probe->checkpoint('a:end');
        $probe->checkpoint('b:end');

        $probe->assertInterleaved('a', 'b');
    }

    #[Test]
    public function assertInterleaved_fails_on_sequential_execution(): void
    {
        $probe = new InterleavingProbe();
        $probe->checkpoint('a:start');
        $probe->checkpoint('a:end');
        $probe->checkpoint('b:start');
        $probe->checkpoint('b:end');

        $this->expectException(ExpectationFailedException::class);
        $probe->assertInterleaved('a', 'b');
    }

    #[Test]
    public function assertInterleaved_passes_when_b_interleaves_a(): void
    {
        $probe = new InterleavingProbe();
        $probe->checkpoint('b:start');
        $probe->checkpoint('a:start');
        $probe->checkpoint('b:end');
        $probe->checkpoint('a:end');

        $probe->assertInterleaved('a', 'b');
    }

    #[Test]
    public function reset_clears_events(): void
    {
        $probe = new InterleavingProbe();
        $probe->checkpoint('a:1');
        $probe->reset();

        $this->assertSame([], $probe->events);
    }
}
