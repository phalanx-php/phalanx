<?php

declare(strict_types=1);

namespace Phalanx\Panoply;

use Phalanx\Panoply\Hash\Canonicalizable;

/**
 * Authorization grant issued by the Authorizer. Carries the grant's ULID,
 * the subject agent id, the closed set of permitted effect kinds, the
 * scope identifier, the hazard ceiling, an optional expiry, and opaque
 * conditions evaluated at authorization time.
 *
 * Duplicate effect kinds are removed on construction. Conditions are
 * sorted lexicographically by key on construction so that the property
 * and {@see self::toCanonical()} always agree on order.
 *
 * `final` because subclassing would alter {@see self::toCanonical()} and
 * break Canonical hash stability.
 */
final class Grant implements Canonicalizable
{
    /** @var list<Effect\Kind> */
    private(set) array $allowedEffects;

    /** @var array<string, mixed> */
    private(set) array $conditions;

    /**
     * @param list<Effect\Kind>    $allowedEffects
     * @param array<string, mixed> $conditions
     */
    public function __construct(
        private(set) string $id,
        private(set) string $subject,
        array $allowedEffects,
        private(set) string $scope,
        private(set) Hazard $hazardCeiling,
        private(set) ?\DateTimeImmutable $expiresAt = null,
        array $conditions = [],
    ) {
        $this->allowedEffects = self::dedupKinds($allowedEffects);
        ksort($conditions);
        $this->conditions = $conditions;
    }

    /**
     * @param list<Effect\Kind>    $allowedEffects
     * @param array<string, mixed> $conditions
     */
    public static function of(
        string $id,
        string $subject,
        array $allowedEffects,
        string $scope,
        Hazard $hazardCeiling,
        ?\DateTimeImmutable $expiresAt = null,
        array $conditions = [],
    ): self {
        return new self(
            id: $id,
            subject: $subject,
            allowedEffects: $allowedEffects,
            scope: $scope,
            hazardCeiling: $hazardCeiling,
            expiresAt: $expiresAt,
            conditions: $conditions,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toCanonical(): array
    {
        $allowed = array_map(static fn (Effect\Kind $k): string => $k->value, $this->allowedEffects);
        sort($allowed);

        return [
            'id'              => $this->id,
            'subject'         => $this->subject,
            'allowed_effects' => $allowed,
            'scope'           => $this->scope,
            'hazard_ceiling'  => $this->hazardCeiling->value,
            'expires_at'      => $this->expiresAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'conditions'      => $this->conditions,
        ];
    }

    public function withExpiry(\DateTimeImmutable $expiresAt): self
    {
        return new self(
            id: $this->id,
            subject: $this->subject,
            allowedEffects: $this->allowedEffects,
            scope: $this->scope,
            hazardCeiling: $this->hazardCeiling,
            expiresAt: $expiresAt,
            conditions: $this->conditions,
        );
    }

    public function withCondition(string $key, mixed $value): self
    {
        return new self(
            id: $this->id,
            subject: $this->subject,
            allowedEffects: $this->allowedEffects,
            scope: $this->scope,
            hazardCeiling: $this->hazardCeiling,
            expiresAt: $this->expiresAt,
            conditions: array_merge($this->conditions, [$key => $value]),
        );
    }

    public function permits(Effect\Kind $kind): bool
    {
        return array_any($this->allowedEffects, static fn (Effect\Kind $k): bool => $k === $kind);
    }

    public function isExpired(\DateTimeImmutable $at): bool
    {
        return $this->expiresAt !== null && $at >= $this->expiresAt;
    }

    /**
     * @param list<Effect\Kind> $kinds
     * @return list<Effect\Kind>
     */
    private static function dedupKinds(array $kinds): array
    {
        $seen = [];
        $out  = [];
        foreach ($kinds as $kind) {
            if (isset($seen[$kind->value])) {
                continue;
            }
            $seen[$kind->value] = true;
            $out[]              = $kind;
        }

        return $out;
    }
}
