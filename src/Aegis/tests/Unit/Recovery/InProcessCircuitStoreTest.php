<?php

declare(strict_types=1);

namespace Phalanx\Aegis\Tests\Unit\Recovery;

use Phalanx\Mark\Mark;
use Phalanx\Recovery\Circuit;
use Phalanx\Recovery\CircuitKey;
use Phalanx\Recovery\CircuitState;
use Phalanx\Recovery\InProcessCircuitStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InProcessCircuitStoreTest extends TestCase
{
    #[Test]
    public function startsClosedByDefault(): void
    {
        $store = new InProcessCircuitStore();
        $circuit = $this->circuit();

        $snapshot = $store->beforeAttempt($circuit);

        self::assertSame(CircuitState::Closed, $snapshot->state);
        self::assertSame(0, $snapshot->failureCount);
    }

    #[Test]
    public function opensAfterFailureThreshold(): void
    {
        $store = new InProcessCircuitStore();
        $circuit = $this->circuit(threshold: 3);

        for ($i = 0; $i < 3; $i++) {
            $store->recordFailure($circuit, new RuntimeException('fail'));
        }

        $snapshot = $store->beforeAttempt($circuit);

        self::assertSame(CircuitState::Open, $snapshot->state);
    }

    #[Test]
    public function successResetsFailureCount(): void
    {
        $store = new InProcessCircuitStore();
        $circuit = $this->circuit(threshold: 3);

        $store->recordFailure($circuit, new RuntimeException('fail'));
        $store->recordFailure($circuit, new RuntimeException('fail'));
        $store->recordSuccess($circuit);

        $snapshot = $store->beforeAttempt($circuit);

        self::assertSame(CircuitState::Closed, $snapshot->state);
        self::assertSame(0, $snapshot->failureCount);
    }

    #[Test]
    public function keyedIsolation(): void
    {
        $store = new InProcessCircuitStore();
        $circuitA = Circuit::named(CircuitKey::from('api-a'))->openAfter(2, Mark::s(60));
        $circuitB = Circuit::named(CircuitKey::from('api-b'))->openAfter(2, Mark::s(60));

        $store->recordFailure($circuitA, new RuntimeException('fail'));
        $store->recordFailure($circuitA, new RuntimeException('fail'));

        $snapshotA = $store->beforeAttempt($circuitA);
        $snapshotB = $store->beforeAttempt($circuitB);

        self::assertSame(CircuitState::Open, $snapshotA->state);
        self::assertSame(CircuitState::Closed, $snapshotB->state);
    }

    #[Test]
    public function halfOpenProbeFailureReopens(): void
    {
        $store = new InProcessCircuitStore();
        $shortCooldown = $this->circuit(threshold: 1, cooldown: Mark::ns(1));
        $longCooldown = Circuit::named(CircuitKey::from('test'))
            ->openAfter(1, Mark::s(60))
            ->cooldown(Mark::s(60));

        $store->recordFailure($shortCooldown, new RuntimeException('fail'));

        $snapshot = $store->beforeAttempt($shortCooldown);

        self::assertSame(CircuitState::HalfOpen, $snapshot->state);

        $store->recordFailure($shortCooldown, new RuntimeException('fail again'));

        $snapshot = $store->beforeAttempt($longCooldown);

        self::assertSame(CircuitState::Open, $snapshot->state);
    }

    #[Test]
    public function halfOpenProbeSuccessCloses(): void
    {
        $store = new InProcessCircuitStore();
        $circuit = $this->circuit(threshold: 1, cooldown: Mark::ns(1));

        $store->recordFailure($circuit, new RuntimeException('fail'));

        $store->beforeAttempt($circuit);
        $store->recordSuccess($circuit);

        $snapshot = $store->beforeAttempt($circuit);

        self::assertSame(CircuitState::Closed, $snapshot->state);
    }

    private function circuit(int $threshold = 5, ?Mark $cooldown = null): Circuit
    {
        return Circuit::named(CircuitKey::from('test'))
            ->openAfter($threshold, Mark::s(60))
            ->cooldown($cooldown ?? Mark::s(30));
    }
}
