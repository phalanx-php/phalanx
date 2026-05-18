<?php

declare(strict_types=1);

namespace Phalanx\Athena\Grant;

use Phalanx\Panoply\Effect\Kind;
use Phalanx\Panoply\Grant;
use Phalanx\Panoply\Hazard;
use Phalanx\Scope\TaskScope;
use Phalanx\Surreal\Surreal;

final class SurrealGrantStore implements Store
{
    /** @var array<string, Grant> */
    private array $sessionGrants = [];

    public function __construct(
        private(set) Surreal $surreal,
    ) {
    }

    public function find(TaskScope $scope, string $subject, Kind $kind, array $arguments = []): ?Grant
    {
        $scope->throwIfCancelled();

        $now = new \DateTimeImmutable();
        foreach ($this->sessionGrants as $grant) {
            if ($grant->subject === $subject && $grant->permits($kind) && !$grant->isExpired($now)) {
                return $grant;
            }
        }

        $results = $this->surreal->query(
            'SELECT * FROM athena_grant WHERE subject = $subject LIMIT 1',
            ['subject' => $subject],
        );

        $rows = self::firstResult($results);
        if ($rows === []) {
            return null;
        }

        $grant = self::hydrate($rows[0]);

        if (!$grant->permits($kind) || $grant->isExpired($now)) {
            return null;
        }

        return $grant;
    }

    public function remember(TaskScope $scope, Grant $grant): void
    {
        $scope->throwIfCancelled();

        if ($grant->scope === Scope::Session->value) {
            $this->sessionGrants[$grant->id] = $grant;

            return;
        }

        $this->surreal->upsert('athena_grant:' . $grant->id, [
            'subject'         => $grant->subject,
            'allowed_effects' => array_map(static fn(Kind $k): string => $k->value, $grant->allowedEffects),
            'scope'           => $grant->scope,
            'hazard_ceiling'  => $grant->hazardCeiling->value,
            'expires_at'      => $grant->expiresAt?->format(\DateTimeInterface::RFC3339_EXTENDED),
            'conditions'      => $grant->conditions,
        ]);
    }

    public function consume(TaskScope $scope, Grant $grant): void
    {
        $scope->throwIfCancelled();

        if ($grant->scope !== Scope::Once->value) {
            return;
        }

        unset($this->sessionGrants[$grant->id]);
        $this->surreal->delete('athena_grant:' . $grant->id);
    }

    public function revoke(TaskScope $scope, string $grantId): void
    {
        $scope->throwIfCancelled();

        unset($this->sessionGrants[$grantId]);
        $this->surreal->delete('athena_grant:' . $grantId);
    }

    public function clearSession(): void
    {
        $this->sessionGrants = [];
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): Grant
    {
        $rawEffects = $row['allowed_effects'] ?? [];
        /** @var list<Kind> $allowedEffects */
        $allowedEffects = array_map(
            static fn(string $v): Kind => Kind::from($v),
            is_array($rawEffects) ? array_values($rawEffects) : [],
        );

        return new Grant(
            id: (string) $row['id'],
            subject: (string) $row['subject'],
            allowedEffects: $allowedEffects,
            scope: (string) $row['scope'],
            hazardCeiling: Hazard::from((string) $row['hazard_ceiling']),
            expiresAt: isset($row['expires_at']) ? new \DateTimeImmutable((string) $row['expires_at']) : null,
            conditions: (array) ($row['conditions'] ?? []),
        );
    }

    /**
     * @param list<mixed>|null $results
     * @return list<array<string, mixed>>
     */
    private static function firstResult(?array $results): array
    {
        if ($results === null || $results === []) {
            return [];
        }

        $first = $results[0];
        if (!is_array($first)) {
            return [];
        }

        /** @var list<array<string, mixed>> $first */
        return $first;
    }
}
