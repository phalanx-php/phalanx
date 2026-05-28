<?php

declare(strict_types=1);

namespace AgentBridge\Policy;

final readonly class PolicyRule
{
    public function __construct(
        public string $legoName,
        /** @var array<string, mixed> */
        public array $match,
        public float $confidence,
        public int $applied,
        public int $overridden,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            legoName: $data['legoName'],
            match: $data['match'] ?? [],
            confidence: (float) ($data['confidence'] ?? 0.0),
            applied: (int) ($data['applied'] ?? 0),
            overridden: (int) ($data['overridden'] ?? 0),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'legoName' => $this->legoName,
            'match' => $this->match,
            'confidence' => $this->confidence,
            'applied' => $this->applied,
            'overridden' => $this->overridden,
        ];
    }
}
