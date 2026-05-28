<?php

declare(strict_types=1);

namespace AgentBridge\Lego;

/**
 * Immutable value object describing a named, reusable action sequence for a domain.
 *
 * Tracking counters (executions, failures, overrides) are updated via copy-on-write
 * factory methods so callers always hold a consistent snapshot. The library persists
 * each returned instance; the caller is responsible for discarding the old reference.
 *
 * $confidence is a derived property — no setter, computed from executions + failures
 * + overrides. Zero executions returns a neutral 0.5 ("never tried, assume plausible").
 */
final class LegoDefinition
{
    public float $confidence {
        get {
            if ($this->executions === 0) {
                return 0.5;
            }

            $successRate     = 1.0 - ($this->failures / max($this->executions, 1));
            $overridePenalty = min($this->overrides * 0.1, 0.5);

            return max(0.0, min(1.0, $successRate - $overridePenalty));
        }
    }

    public function __construct(
        private(set) string $name,
        private(set) string $domain,
        private(set) string $description,
        /** @var list<array{op: string, selector?: string, value?: string, timeoutMs?: int}> */
        private(set) array $steps,
        private(set) int $executions = 0,
        private(set) int $failures = 0,
        private(set) int $overrides = 0,
        private(set) ?string $lastVerified = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name:         $data['name'],
            domain:       $data['domain'],
            description:  $data['description'] ?? '',
            steps:        $data['steps'],
            executions:   $data['executions'] ?? 0,
            failures:     $data['failures'] ?? 0,
            overrides:    $data['overrides'] ?? 0,
            lastVerified: $data['lastVerified'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name'         => $this->name,
            'domain'       => $this->domain,
            'description'  => $this->description,
            'steps'        => $this->steps,
            'executions'   => $this->executions,
            'failures'     => $this->failures,
            'overrides'    => $this->overrides,
            'lastVerified' => $this->lastVerified,
        ];
    }

    /**
     * Returns a new instance with updated execution/failure counters and lastVerified timestamp.
     */
    public function withExecution(bool $succeeded): self
    {
        return new self(
            name:         $this->name,
            domain:       $this->domain,
            description:  $this->description,
            steps:        $this->steps,
            executions:   $this->executions + 1,
            failures:     $this->failures + ($succeeded ? 0 : 1),
            overrides:    $this->overrides,
            lastVerified: date('c'),
        );
    }

    /**
     * Returns a new instance with the override counter incremented.
     * Called when the user manually corrects the agent's action choice.
     */
    public function withOverride(): self
    {
        return new self(
            name:         $this->name,
            domain:       $this->domain,
            description:  $this->description,
            steps:        $this->steps,
            executions:   $this->executions,
            failures:     $this->failures,
            overrides:    $this->overrides + 1,
            lastVerified: $this->lastVerified,
        );
    }

    /**
     * Returns a new instance with replaced steps and a reset failure count.
     * Called after the RepairAgent produces a corrected step sequence.
     */
    public function withRepairedSteps(array $steps): self
    {
        return new self(
            name:         $this->name,
            domain:       $this->domain,
            description:  $this->description,
            steps:        $steps,
            executions:   $this->executions,
            failures:     0,
            overrides:    $this->overrides,
            lastVerified: null,
        );
    }
}
