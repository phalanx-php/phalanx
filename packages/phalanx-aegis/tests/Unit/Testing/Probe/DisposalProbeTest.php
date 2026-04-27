<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Testing\Probe;

use Phalanx\Testing\Probe\DisposalProbe;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DisposalProbeTest extends TestCase
{
    #[Test]
    public function track_returns_invokable_closure(): void
    {
        $probe = new DisposalProbe();
        $callback = $probe->track('service-a');

        $callback();

        $this->assertSame(['service-a'], $probe->log);
    }

    #[Test]
    public function tracks_multiple_labels(): void
    {
        $probe = new DisposalProbe();
        $probe->track('first')();
        $probe->track('second')();
        $probe->track('third')();

        $this->assertSame(['first', 'second', 'third'], $probe->log);
    }

    #[Test]
    public function assertDisposed_passes_when_all_present(): void
    {
        $probe = new DisposalProbe();
        $probe->track('a')();
        $probe->track('b')();
        $probe->track('c')();

        $probe->assertDisposed('a', 'b', 'c');
        $probe->assertDisposed('b', 'a');
    }

    #[Test]
    public function assertDisposedInOrder_validates_sequence(): void
    {
        $probe = new DisposalProbe();
        $probe->track('third')();
        $probe->track('second')();
        $probe->track('first')();

        $probe->assertDisposedInOrder('third', 'second', 'first');
    }

    #[Test]
    public function assertDisposedInOrder_filters_to_expected_labels(): void
    {
        $probe = new DisposalProbe();
        $probe->track('a')();
        $probe->track('noise')();
        $probe->track('b')();

        $probe->assertDisposedInOrder('a', 'b');
    }

    #[Test]
    public function reset_clears_log(): void
    {
        $probe = new DisposalProbe();
        $probe->track('a')();
        $probe->reset();

        $this->assertSame([], $probe->log);
    }
}
