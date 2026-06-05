<?php

declare(strict_types=1);

namespace Phalanx\Agents\Grant;

use Phalanx\AiProviders\Effect\Kind;
use Phalanx\AiProviders\Grant;
use Phalanx\Scope\TaskScope;

interface Store
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function find(TaskScope $scope, string $subject, Kind $kind, array $arguments = []): ?Grant;

    public function remember(TaskScope $scope, Grant $grant): void;

    public function consume(TaskScope $scope, Grant $grant): void;

    public function revoke(TaskScope $scope, string $grantId): void;
}
