<?php

declare(strict_types=1);

namespace Phalanx\Enigma\Task;

use Phalanx\ExecutionScope;
use Phalanx\Enigma\CommandResult;
use Phalanx\Enigma\SshCredential;
use Phalanx\Task\Executable;

final class MultiDeploy implements Executable
{
    /**
     * @param list<SshCredential> $credentials
     */
    public function __construct(
        private readonly array $credentials,
        private readonly string $localReleasePath,
        private readonly string $remoteBasePath,
        private readonly int $concurrency = 2,
        private readonly int $keepReleases = 5,
        private readonly ?string $migrationsCommand = null,
        private readonly ?string $healthCheckCommand = null,
    ) {}

    /** @return array<int|string, mixed> */
    public function __invoke(ExecutionScope $scope): array
    {
        $localReleasePath = $this->localReleasePath;
        $remoteBasePath = $this->remoteBasePath;
        $keepReleases = $this->keepReleases;
        $migrationsCommand = $this->migrationsCommand;
        $healthCheckCommand = $this->healthCheckCommand;

        return $scope->map(
            $this->credentials,
            static fn(SshCredential $cred): Executable => new Deploy(
                credential: $cred,
                localReleasePath: $localReleasePath,
                remoteBasePath: $remoteBasePath,
                keepReleases: $keepReleases,
                migrationsCommand: $migrationsCommand,
                healthCheckCommand: $healthCheckCommand,
            ),
            $this->concurrency,
        );
    }
}
