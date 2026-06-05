<?php

declare(strict_types=1);

namespace Phalanx\Agent\Grant;

use Phalanx\Agent\Persistence\SurrealDbResult;
use Phalanx\AiProviders\Effect\Kind;
use Phalanx\AiProviders\Grant;
use Phalanx\AiProviders\Hazard;
use Phalanx\Scope\TaskScope;
use Phalanx\SurrealDb\SurrealDb;

final class SurrealDbGrantStore implements Store
{
    /** @var array<string, Grant> */
    private array $sessionGrants = [];

    public function __construct(
        private SurrealDb $surrealdb,
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

        $results = $this->surrealdb->query(
            'SELECT * FROM agent_grant WHERE subject = $subject LIMIT 1',
            ['subject' => $subject],
        );

        $rows = SurrealDbResult::firstRows($results);
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

        $this->surrealdb->upsert('agent_grant:' . $grant->id, [
            'subject' => $grant->subject,
            'allowed_effects' => array_map(static fn(Kind $k): string => $k->value, $grant->allowedEffects),
            'scope' => $grant->scope,
            'hazard_ceiling' => $grant->hazardCeiling->value,
            'expires_at' => $grant->expiresAt?->format(\DateTimeInterface::RFC3339_EXTENDED),
            'conditions' => $grant->conditions,
        ]);
    }

    public function consume(TaskScope $scope, Grant $grant): void
    {
        $scope->throwIfCancelled();

        if ($grant->scope !== Scope::Once->value) {
            return;
        }

        unset($this->sessionGrants[$grant->id]);
        $this->surrealdb->delete('agent_grant:' . $grant->id);
    }

    public function revoke(TaskScope $scope, string $grantId): void
    {
        $scope->throwIfCancelled();

        unset($this->sessionGrants[$grantId]);
        $this->surrealdb->delete('agent_grant:' . $grantId);
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
            Kind::from(...),
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
}
