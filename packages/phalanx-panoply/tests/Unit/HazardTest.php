<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit;

use Phalanx\Panoply\Hazard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HazardTest extends TestCase
{
    #[Test]
    public function rankIsStrictlyAscending(): void
    {
        self::assertSame(0, Hazard::None->rank());
        self::assertTrue(Hazard::Low->rank() > Hazard::None->rank());
        self::assertTrue(Hazard::Medium->rank() > Hazard::Low->rank());
        self::assertTrue(Hazard::High->rank() > Hazard::Medium->rank());
        self::assertTrue(Hazard::Critical->rank() > Hazard::High->rank());
    }

    #[Test]
    public function exceedsReturnsTrueWhenRatingIsHigherThanCeiling(): void
    {
        self::assertTrue(Hazard::High->exceeds(Hazard::Low));
        self::assertTrue(Hazard::Critical->exceeds(Hazard::Medium));
        self::assertTrue(Hazard::Medium->exceeds(Hazard::None));
    }

    #[Test]
    public function exceedsReturnsFalseWhenRatingIsAtOrBelowCeiling(): void
    {
        self::assertFalse(Hazard::Low->exceeds(Hazard::Low));
        self::assertFalse(Hazard::None->exceeds(Hazard::Critical));
        self::assertFalse(Hazard::High->exceeds(Hazard::Critical));
    }

    #[Test]
    public function exceedsReturnsFalseForEqualRanks(): void
    {
        foreach (Hazard::cases() as $hazard) {
            self::assertFalse($hazard->exceeds($hazard), "{$hazard->value} should not exceed itself");
        }
    }

    #[Test]
    public function valuesMatchExpectedStrings(): void
    {
        self::assertSame('none', Hazard::None->value);
        self::assertSame('low', Hazard::Low->value);
        self::assertSame('medium', Hazard::Medium->value);
        self::assertSame('high', Hazard::High->value);
        self::assertSame('critical', Hazard::Critical->value);
    }

    #[Test]
    public function criticalExceedsAllOthers(): void
    {
        foreach (Hazard::cases() as $other) {
            if ($other === Hazard::Critical) {
                continue;
            }
            self::assertTrue(Hazard::Critical->exceeds($other), "Critical should exceed {$other->value}");
        }
    }

    #[Test]
    public function noneExceedsNothing(): void
    {
        foreach (Hazard::cases() as $other) {
            self::assertFalse(Hazard::None->exceeds($other), "None should not exceed {$other->value}");
        }
    }
}
