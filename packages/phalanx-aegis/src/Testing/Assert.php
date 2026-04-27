<?php

declare(strict_types=1);

namespace Phalanx\Testing;

use Phalanx\Concurrency\SettlementBag;
use Phalanx\Testing\Probe\ConcurrencyProbe;
use PHPUnit\Framework\Assert as PHPUnitAssert;

final class Assert extends PHPUnitAssert
{
    public static function assertConcurrencyBound(ConcurrencyProbe $probe, int $max): void
    {
        self::assertLessThanOrEqual(
            $max,
            $probe->maxConcurrent,
            "Expected max concurrency of $max, observed {$probe->maxConcurrent}",
        );
    }

    public static function assertElapsedBelow(float $elapsedMs, float $maxMs): void
    {
        self::assertLessThan(
            $maxMs,
            $elapsedMs,
            "Expected elapsed < {$maxMs}ms, was {$elapsedMs}ms",
        );
    }

    public static function assertElapsedAbove(float $elapsedMs, float $minMs): void
    {
        self::assertGreaterThan(
            $minMs,
            $elapsedMs,
            "Expected elapsed > {$minMs}ms, was {$elapsedMs}ms",
        );
    }

    /**
     * @param array<string|int, SettlementExpectation> $expectations
     */
    public static function assertSettled(SettlementBag $bag, array $expectations): void
    {
        foreach ($expectations as $key => $expectation) {
            $settlement = $bag->settlement($key);
            self::assertNotNull($settlement, "No settlement found for key '$key'");
            $expectation->verify($key, $settlement);
        }
    }

    public static function ok(mixed $value = null): SettlementExpectation
    {
        return SettlementExpectation::ok($value);
    }

    /**
     * @param class-string<\Throwable> $exceptionClass
     */
    public static function failed(string $exceptionClass, ?string $message = null): SettlementExpectation
    {
        return SettlementExpectation::failed($exceptionClass, $message);
    }
}
