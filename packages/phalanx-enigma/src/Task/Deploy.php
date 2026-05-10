<?php

declare(strict_types=1);

namespace Phalanx\Enigma\Task;

use Phalanx\Enigma\CommandResult;
use Phalanx\Enigma\SshCredential;
use Phalanx\Enigma\TransferDirection;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\HasTimeout;

final class Deploy implements Executable, HasTimeout
{
    public float $timeout {
        get => $this->timeoutSeconds ?? 0.0;
    }

    private int $keep {
        get => $this->keepReleases + 1;
    }

    public function __construct(
        private readonly SshCredential $credential,
        private readonly string $localReleasePath,
        private readonly string $remoteBasePath,
        private readonly int $keepReleases = 5,
        private readonly ?string $migrationsCommand = null,
        private readonly ?string $healthCheckCommand = null,
        private readonly ?float $timeoutSeconds = null,
    ) {
    }

    public function __invoke(ExecutionScope $scope): CommandResult
    {
        $timestamp = date('Ymd-His');
        $releasesDir = "{$this->remoteBasePath}/releases";
        $releaseDir = "{$releasesDir}/{$timestamp}";
        $currentLink = "{$this->remoteBasePath}/current";
        $remoteTarball = "/tmp/phalanx-release-{$timestamp}.tar.gz";

        $eReleasesDir    = escapeshellarg($releasesDir);
        $eReleaseDir     = escapeshellarg($releaseDir);
        $eCurrentLink    = escapeshellarg($currentLink);
        $eRemoteTarball  = escapeshellarg($remoteTarball);
        $eSharedDir      = escapeshellarg("{$this->remoteBasePath}/shared");

        $scope->execute(new RunCommand(
            credential: $this->credential,
            command: "mkdir -p {$eReleaseDir} {$eSharedDir}",
        ));

        $scope->execute(new ScpTransfer(
            credential: $this->credential,
            from: $this->localReleasePath,
            to: $remoteTarball,
            direction: TransferDirection::Upload,
        ));

        $scope->execute(new RunCommand(
            credential: $this->credential,
            command: "tar -xzf {$eRemoteTarball} -C {$eReleaseDir} && rm -f {$eRemoteTarball}",
        ));

        // $migrationsCommand is a caller-supplied shell command string, not a path argument.
        // It is the caller's responsibility to ensure this value is trusted/sanitised.
        if ($this->migrationsCommand !== null) {
            $scope->execute(new RunCommand(
                credential: $this->credential,
                command: "cd {$eReleaseDir} && {$this->migrationsCommand}",
            ));
        }

        $previousResult = $scope->execute(new RunCommand(
            credential: $this->credential,
            command: "readlink -f {$eCurrentLink} 2>/dev/null || echo ''",
        ));
        $previousRelease = trim((string) $previousResult->stdout);

        $scope->execute(new RunCommand(
            credential: $this->credential,
            command: "ln -sfn {$eReleaseDir} {$eCurrentLink}",
        ));

        // $healthCheckCommand is a caller-supplied shell command string, not a path argument.
        // It is the caller's responsibility to ensure this value is trusted/sanitised.
        if ($this->healthCheckCommand !== null) {
            $healthResult = $scope->execute(new RunCommand(
                credential: $this->credential,
                command: $this->healthCheckCommand,
            ));

            if (!$healthResult->successful && $previousRelease !== '') {
                $ePreviousRelease = escapeshellarg($previousRelease);
                $scope->execute(new RunCommand(
                    credential: $this->credential,
                    command: "ln -sfn {$ePreviousRelease} {$eCurrentLink}",
                ));

                $healthResult->throwIfFailed();
            }
        }

        $keep = $this->keep;
        $scope->execute(new RunCommand(
            credential: $this->credential,
            command: "cd {$eReleasesDir} && ls -1t | tail -n +{$keep} | xargs -I{} rm -rf {}",
        ));

        return $scope->execute(new RunCommand(
            credential: $this->credential,
            command: "readlink -f {$eCurrentLink}",
        ));
    }
}
