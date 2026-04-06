<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Testing\Probe;

use Phalanx\Testing\Probe\LeakSensor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use stdClass;

final class LeakSensorTest extends TestCase
{
    #[Test]
    public function assertAllCollected_passes_when_refs_cleared(): void
    {
        $sensor = new LeakSensor();
        $obj = new stdClass();
        $sensor->watch($obj, 'temp');
        unset($obj);

        $sensor->assertAllCollected();
    }

    #[Test]
    public function assertAllCollected_detects_reference_cycles(): void
    {
        $sensor = new LeakSensor();
        $a = new stdClass();
        $b = new stdClass();
        $a->ref = $b;
        $b->ref = $a;
        $sensor->watch($a, 'cycle-a');
        unset($a, $b);

        $sensor->assertAllCollected();
    }

    #[Test]
    public function assertAllCollected_fails_when_ref_held(): void
    {
        $sensor = new LeakSensor();
        $obj = new stdClass();
        $sensor->watch($obj, 'held');

        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('held');
        $sensor->assertAllCollected();
    }
}
