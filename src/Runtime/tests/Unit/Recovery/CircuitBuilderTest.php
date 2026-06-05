<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Recovery;

use Phalanx\Mark\Mark;
use Phalanx\Recovery\Circuit;
use Phalanx\Recovery\CircuitKey;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CircuitBuilderTest extends TestCase
{
    #[Test]
    public function namedCreatesWithKey(): void
    {
        $circuit = Circuit::named(CircuitKey::from('payments'));

        self::assertSame('payments', $circuit->key->value);
    }

    #[Test]
    public function openAfterReturnsCopy(): void
    {
        $original = Circuit::named(CircuitKey::from('api'));
        $modified = $original->openAfter(10, Mark::s(60));

        self::assertSame(5, $original->failureThreshold);
        self::assertSame(10, $modified->failureThreshold);
        self::assertNotNull($modified->failureWindow);
        self::assertSame(60000, $modified->failureWindow->toMilliseconds());
    }

    #[Test]
    public function cooldownReturnsCopy(): void
    {
        $original = Circuit::named(CircuitKey::from('api'));
        $modified = $original->cooldown(Mark::s(30));

        self::assertNull($original->cooldown);
        self::assertNotNull($modified->cooldown);
        self::assertSame(30000, $modified->cooldown->toMilliseconds());
    }

    #[Test]
    public function halfOpenReturnsCopy(): void
    {
        $original = Circuit::named(CircuitKey::from('api'));
        $modified = $original->halfOpen(5);

        self::assertSame(2, $original->maxProbes);
        self::assertSame(5, $modified->maxProbes);
    }

    #[Test]
    public function fluentChaining(): void
    {
        $circuit = Circuit::named(CircuitKey::from('payments'))
            ->openAfter(5, Mark::s(60))
            ->cooldown(Mark::s(30))
            ->halfOpen(3);

        self::assertSame('payments', $circuit->key->value);
        self::assertSame(5, $circuit->failureThreshold);
        self::assertNotNull($circuit->failureWindow);
        self::assertSame(60000, $circuit->failureWindow->toMilliseconds());
        self::assertNotNull($circuit->cooldown);
        self::assertSame(30000, $circuit->cooldown->toMilliseconds());
        self::assertSame(3, $circuit->maxProbes);
    }

    #[Test]
    public function circuitKeyFromFactory(): void
    {
        $key = CircuitKey::from('test-key');

        self::assertSame('test-key', $key->value);
    }
}
