<?php

declare(strict_types=1);

namespace Phalanx\Agents\Grant;

use Phalanx\AiProviders\Effect\Kind;
use Phalanx\AiProviders\Grant;
use Phalanx\Scope\TaskScope;

final class MemoryGrantStore implements Store
{
    /** @var array<string, Grant> */
    private array $grants = [];

    public function find(TaskScope $scope, string $subject, Kind $kind, array $arguments = []): ?Grant
    {
        $now = new \DateTimeImmutable();

        foreach ($this->grants as $grant) {
            if ($grant->subject === $subject && $grant->permits($kind) && !$grant->isExpired($now)) {
                return $grant;
            }
        }

        return null;
    }

    public function remember(TaskScope $scope, Grant $grant): void
    {
        $this->grants[$grant->id] = $grant;
    }

    public function consume(TaskScope $scope, Grant $grant): void
    {
        unset($this->grants[$grant->id]);
    }

    public function revoke(TaskScope $scope, string $grantId): void
    {
        unset($this->grants[$grantId]);
    }
}
