<?php

declare(strict_types=1);

namespace Phalanx\Enigma\Task;

use Phalanx\Enigma\Exception\SshException;
use Phalanx\Enigma\SshConfig;
use Phalanx\Enigma\SshCredential;
use Phalanx\Enigma\Support\LocalTempFile;
use Phalanx\Enigma\Support\ProcessAwaiter;
use Phalanx\Enigma\TransferResult;
use Phalanx\Grammata\Exception\FilesystemException;
use Phalanx\Grammata\Task\StatFile;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\HasTimeout;

final class SftpDownload implements Executable, HasTimeout
{
    public float $timeout {
        get => $this->timeoutSeconds ?? 0.0;
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
