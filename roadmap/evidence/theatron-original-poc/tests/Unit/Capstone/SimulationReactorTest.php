<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Capstone;

use Phalanx\Theatron\Demos\Capstone\Reactor\SimulationReactor;
use Phalanx\Theatron\Reactor\ReactorExclusivity;
use Phalanx\Theatron\Reactor\ReactorState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SimulationReactorTest extends TestCase
{
    #[Test]
    public function initial_state_is_idle(): void
    {
        $reactor = new SimulationReactor();

        self::assertSame(ReactorState::Idle, $reactor->state);
    }

    #[Test]
    public function id_is_capstone_simulation(): void
    {
        $reactor = new SimulationReactor();

        self::assertSame('capstone.simulation', $reactor->id);
    }

    #[Test]
    public function exclusivity_is_concurrent(): void
    {
        $reactor = new SimulationReactor();

        self::assertSame(ReactorExclusivity::Concurrent, $reactor->exclusivity);
    }

    #[Test]
    public function group_is_null(): void
    {
        $reactor = new SimulationReactor();

        self::assertNull($reactor->group);
    }

    #[Test]
    public function cancel_sets_cancelled_state(): void
    {
        $reactor = new SimulationReactor();
        $reactor->cancel();

        self::assertSame(ReactorState::Cancelled, $reactor->state);
    }
}
