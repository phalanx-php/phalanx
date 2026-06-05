<?php

declare(strict_types=1);

namespace Phalanx\Ssh\Task;

use Phalanx\Ssh\Exception\SshException;
use Phalanx\Ssh\SshConfig;
use Phalanx\Ssh\SshCredential;
use Phalanx\Ssh\Support\ProcessAwaiter;
use Phalanx\Ssh\TransferDirection;
use Phalanx\Ssh\TransferResult;
use Phalanx\Filesystem\Exception\FilesystemException;
use Phalanx\Filesystem\Task\StatFile;
use Phalanx\Mark\Mark;
use Phalanx\Recovery\Recoverable;
use Phalanx\Recovery\RecoveryPlan;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;

final class ScpTransfer implements Executable, Recoverable
{
    public RecoveryPlan $recovery {
        get => $this->timeoutSeconds !== null
            ? RecoveryPlan::failFast(deadline: Mark::s($this->timeoutSeconds))
            : RecoveryPlan::none();
    }

    public function __construct(
        private readonly SshCredential $credential,
        private readonly string $from,
        private readonly string $to,
        private readonly TransferDirection $direction,
        private readonly ?float $timeoutSeconds = null,
    ) {
    }

    public function __invoke(ExecutionScope $scope): TransferResult
    {
        /** @var SshConfig $config */
        $config = $scope->service(SshConfig::class);
        $prefix = $this->credential->toScpPrefix();

        [$source, $dest] = match ($this->direction) {
            TransferDirection::Upload => [$this->from, $prefix . $this->to],
            TransferDirection::Download => [$prefix . $this->from, $this->to],
        };

        $args = [
            ...$this->credential->toScpArgs($config),
            $source,
            $dest,
        ];

        [$exitCode, , , $durationMs] = ProcessAwaiter::spawn(
            ProcessAwaiter::argv($config->scpBinaryPath, $args),
            $scope,
            $this->timeoutSeconds ?? $config->defaultTimeoutSeconds,
        );

        if ($exitCode !== 0) {
            throw new SshException("SCP transfer failed (exit {$exitCode})", $exitCode);
        }

        $localFile = match ($this->direction) {
            TransferDirection::Upload => $this->from,
            TransferDirection::Download => $this->to,
        };
        try {
            $bytes = $scope->execute(new StatFile($localFile))->size;
        } catch (FilesystemException) {
            $bytes = 0;
        }

        [$localPath, $remotePath] = match ($this->direction) {
            TransferDirection::Upload => [$this->from, $this->to],
            TransferDirection::Download => [$this->to, $this->from],
        };

        return new TransferResult(
            localPath: $localPath,
            remotePath: $remotePath,
            bytesTransferred: $bytes,
            durationMs: $durationMs,
        );
    }
}
