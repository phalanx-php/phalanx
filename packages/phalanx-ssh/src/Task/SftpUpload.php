<?php

declare(strict_types=1);

namespace Phalanx\Ssh\Task;

use Phalanx\ExecutionScope;
use Phalanx\Ssh\Exception\SshException;
use Phalanx\Ssh\SshConfig;
use Phalanx\Ssh\SshCredential;
use Phalanx\Ssh\Support\ProcessAwaiter;
use Phalanx\Ssh\TransferResult;
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
            $tempFile = tempnam(sys_get_temp_dir(), 'phalanx-sftp-') ?: sys_get_temp_dir() . '/phalanx-sftp-' . bin2hex(random_bytes(8));
            file_put_contents($tempFile, $this->localContent);
            $actualLocalPath = $tempFile;

            $scope->onDispose(static function () use ($tempFile): void {
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
            });
        }

        \assert($actualLocalPath !== null);

        $batchFile = tempnam(sys_get_temp_dir(), 'phalanx-sftp-batch-') ?: sys_get_temp_dir() . '/phalanx-sftp-batch-' . bin2hex(random_bytes(8));
        file_put_contents($batchFile, "put {$actualLocalPath} {$this->remotePath}\n");

        $scope->onDispose(static function () use ($batchFile): void {
            if (file_exists($batchFile)) {
                unlink($batchFile);
            }
        });

        $args = ['-b', $batchFile, ...$this->credential->toSftpArgs($config)];
        $cmdLine = ProcessAwaiter::buildCommandLine($config->sftpBinaryPath, $args);

        [$exitCode, , , $durationMs] = ProcessAwaiter::spawn($cmdLine, $scope);

        if ($exitCode !== 0) {
            throw new SshException("SFTP upload failed (exit {$exitCode})", $exitCode);
        }

        $bytes = (int) filesize($actualLocalPath);

        return new TransferResult(
            localPath: $this->localPath ?? '(content)',
            remotePath: $this->remotePath,
            bytesTransferred: $bytes,
            durationMs: $durationMs,
        );
    }
}
