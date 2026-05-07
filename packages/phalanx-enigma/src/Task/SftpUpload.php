<?php

declare(strict_types=1);

namespace Phalanx\Enigma\Task;

use Phalanx\Enigma\Exception\SshException;
use Phalanx\Enigma\SshConfig;
use Phalanx\Enigma\SshCredential;
use Phalanx\Enigma\Support\LocalTempFile;
use Phalanx\Enigma\Support\ProcessAwaiter;
use Phalanx\Enigma\TransferResult;
use Phalanx\Grammata\Task\StatFile;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\HasTimeout;

final class SftpUpload implements Executable, HasTimeout
{
    public float $timeout {
        get => $this->timeoutSeconds ?? 0.0;
    }

    /**
     * @param string|null $localPath    Path to a local file to upload
     * @param string|null $localContent Raw content to upload (written to temp file)
     * @param string      $remotePath   Destination path on the remote host
     */
    public function __construct(
        private readonly SshCredential $credential,
        private readonly string $remotePath,
        private readonly ?string $localPath = null,
        private readonly ?string $localContent = null,
        private readonly ?float $timeoutSeconds = null,
    ) {
        if ($this->localPath === null && $this->localContent === null) {
            throw new \InvalidArgumentException('Either localPath or localContent must be provided');
        }
    }

    public function __invoke(ExecutionScope $scope): TransferResult
    {
        /** @var SshConfig $config */
        $config = $scope->service(SshConfig::class);
        $actualLocalPath = $this->localPath;

        if ($this->localContent !== null) {
            $tempFile = LocalTempFile::write($scope, 'phalanx-sftp-', $this->localContent);
            $actualLocalPath = $tempFile;
        }

        \assert($actualLocalPath !== null);

        $batchFile = LocalTempFile::write(
            $scope,
            'phalanx-sftp-batch-',
            "put {$actualLocalPath} {$this->remotePath}\n",
        );
        $args = ['-b', $batchFile, ...$this->credential->toSftpArgs($config)];

        [$exitCode, , , $durationMs] = ProcessAwaiter::spawn(
            ProcessAwaiter::argv($config->sftpBinaryPath, $args),
            $scope,
            $this->timeoutSeconds ?? $config->defaultTimeoutSeconds,
        );

        if ($exitCode !== 0) {
            throw new SshException("SFTP upload failed (exit {$exitCode})", $exitCode);
        }

        $bytes = $scope->execute(new StatFile($actualLocalPath))->size;

        return new TransferResult(
            localPath: $this->localPath ?? '(content)',
            remotePath: $this->remotePath,
            bytesTransferred: $bytes,
            durationMs: $durationMs,
        );
    }
}
