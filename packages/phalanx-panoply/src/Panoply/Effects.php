<?php

declare(strict_types=1);

namespace Phalanx\Panoply;

use Phalanx\Panoply\Effect\Kind;
use Phalanx\Panoply\Hash\Canonicalizable;

/**
 * Final — canonical hash determinism: subclassing would alter toCanonical()
 * and break hash stability across consumers.
 *
 * Immutable declaration of which effect kinds an agent may request and
 * which of those require human approval before the host executes them.
 *
 * Authorization semantics — hazard scoring, grant matching, denial reason
 * codes — live in `Effect\Authorizer`. This type only carries the agent's
 * declared surface.
 */
final class Effects implements Canonicalizable
{
    /** @var list<Kind> */
    private(set) array $allowed;

    /** @var list<Kind> */
    private(set) array $requiresApproval;

    /**
     * @param list<Kind> $allowed
     * @param list<Kind> $requiresApproval
     */
    public function __construct(array $allowed = [], array $requiresApproval = [])
    {
        $this->allowed = self::dedup($allowed);
        $this->requiresApproval = self::dedup($requiresApproval);
    }

    public static function none(): self
    {
        return new self();
    }

    public static function allow(Kind ...$kinds): self
    {
        return new self(array_values($kinds));
    }

    /**
     * @return array{allowed: list<string>, requires_approval: list<string>}
     */
    public function toCanonical(): array
    {
        $allowed = array_map(static fn (Kind $k): string => $k->value, $this->allowed);
        sort($allowed);

        $approval = array_map(static fn (Kind $k): string => $k->value, $this->requiresApproval);
        sort($approval);

        return ['allowed' => $allowed, 'requires_approval' => $approval];
    }

    public function withAllowed(Kind ...$kinds): self
    {
        if ($kinds === []) {
            return $this;
        }

        return new self(
            [...$this->allowed, ...array_values($kinds)],
            $this->requiresApproval,
        );
    }

    public function requireApproval(Kind ...$kinds): self
    {
        if ($kinds === []) {
            return $this;
        }

        return new self(
            $this->allowed,
            [...$this->requiresApproval, ...array_values($kinds)],
        );
    }

    public function permits(Kind $kind): bool
    {
        return array_any($this->allowed, static fn (Kind $existing): bool => $existing === $kind);
    }

    public function needsApproval(Kind $kind): bool
    {
        return array_any($this->requiresApproval, static fn (Kind $existing): bool => $existing === $kind);
    }

    public function isEmpty(): bool
    {
        return $this->allowed === [] && $this->requiresApproval === [];
    }

    /**
     * @param list<Kind> $kinds
     * @return list<Kind>
     */
    private static function dedup(array $kinds): array
    {
        $seen = [];
        $out = [];
        foreach ($kinds as $kind) {
            if (isset($seen[$kind->value])) {
                continue;
            }
            $seen[$kind->value] = true;
            $out[] = $kind;
        }

        return $out;
    }
}
