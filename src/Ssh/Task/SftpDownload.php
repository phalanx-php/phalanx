<?php

declare(strict_types=1);

namespace Phalanx\Ssh\Task;

use Phalanx\Ssh\Exception\SshException;
use Phalanx\Ssh\SshConfig;
use Phalanx\Ssh\SshCredential;
use Phalanx\Ssh\Support\LocalTempFile;
use Phalanx\Ssh\Support\ProcessAwaiter;
use Phalanx\Ssh\TransferResult;
use Phalanx\Filesystem\Exception\FilesystemException;
use Phalanx\Filesystem\Task\StatFile;
use Phalanx\Mark\Mark;
use Phalanx\Recovery\Recoverable;
use Phalanx\Recovery\RecoveryPlan;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;

final class SftpDownload implements Executable, Recoverable
{
    public RecoveryPlan $recovery {
        get => $this->timeoutSeconds !== null
            ? RecoveryPlan::failFast(deadline: Mark::s($this->timeoutSeconds))
            : RecoveryPlan::none();
    }

    public function __construct(
        private readonly SshCredential $credential,
        private readonly string $remotePath,
        private readonly string $localPath,
        private readonly ?float $timeoutSeconds = null,
    ) {
    }

    public function __invoke(ExecutionScope $scope): TransferResult
    {
        /** @var SshConfig $config */
        $config = $scope->service(SshConfig::class);

        $batchFile = LocalTempFile::write(
            $scope,
            'phalanx-sftp-batch-',
            "get {$this->remotePath} {$this->localPath}\n",
        );
        $args = ['-b', $batchFile, ...$this->credential->toSftpArgs($config)];

        [$exitCode, , , $durationMs] = ProcessAwaiter::spawn(
            ProcessAwaiter::argv($config->sftpBinaryPath, $args),
            $scope,
            $this->timeoutSeconds ?? $config->defaultTimeoutSeconds,
        );

        if ($exitCode !== 0) {
            throw new SshException("SFTP download failed (exit {$exitCode})", $exitCode);
        }

        try {
            $bytes = $scope->execute(new StatFile($this->localPath))->size;
        } catch (FilesystemException) {
            $bytes = 0;
        }

        return new TransferResult(
            localPath: $this->localPath,
            remotePath: $this->remotePath,
            bytesTransferred: $bytes,
            durationMs: $durationMs,
        );
    }
}
