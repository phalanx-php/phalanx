<?php

declare(strict_types=1);

namespace BgAgents\Specialist;

final readonly class SpecialistResponse
{
    public function __construct(
        public string $from,
        public string $model,
        public string $provider,
        public string $text,
        public int $tokensIn,
        public int $tokensOut,
        public int $steps,
        public float $latencyMs,
    ) {}
}
