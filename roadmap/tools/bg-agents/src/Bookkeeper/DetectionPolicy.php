<?php

declare(strict_types=1);

namespace BgAgents\Bookkeeper;

/**
 * Tunable thresholds + intervals for the bookkeeper lanes.
 *
 * Defaults are conservative for production; BG_AGENTS_BOOKKEEPER_FAST=1
 * shrinks intervals to dev-friendly values so the lanes fire within a
 * single test session.
 */
final readonly class DetectionPolicy
{
    public function __construct(
        public int $duplicateWindowSec,
        public int $consolidationIntervalSec,
        public int $consolidationLookbackSec,
        public int $consolidationNoiseThreshold,
        public int $promotionIntervalSec,
        public int $promotionLookbackSec,
        public int $promotionRelateThreshold,
    ) {}

    /** @param array<string, mixed> $context */
    public static function fromContext(array $context): self
    {
        $fast = filter_var($context['BG_AGENTS_BOOKKEEPER_FAST'] ?? false, FILTER_VALIDATE_BOOL);

        return $fast ? self::fast() : self::standard();
    }

    public static function standard(): self
    {
        return new self(
            duplicateWindowSec: 60,
            consolidationIntervalSec: 300,
            consolidationLookbackSec: 900,
            consolidationNoiseThreshold: 20,
            promotionIntervalSec: 1800,
            promotionLookbackSec: 86400,
            promotionRelateThreshold: 3,
        );
    }

    public static function fast(): self
    {
        return new self(
            duplicateWindowSec: 15,
            consolidationIntervalSec: 30,
            consolidationLookbackSec: 120,
            consolidationNoiseThreshold: 5,
            promotionIntervalSec: 60,
            promotionLookbackSec: 300,
            promotionRelateThreshold: 2,
        );
    }
}
