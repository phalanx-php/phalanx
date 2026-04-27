<?php

declare(strict_types=1);

namespace Phalanx\Enigma\Task;

use Phalanx\ExecutionScope;
use Phalanx\Enigma\Exception\SshException;
use Phalanx\Enigma\SshConfig;
use Phalanx\Enigma\SshCredential;
use Phalanx\Enigma\Support\ProcessAwaiter;
use Phalanx\Enigma\TransferResult;
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
    ) {}

    public function __invoke(ExecutionScope $scope): TransferResult
    {
        /** @var SshConfig $config */
        $config = $scope->service(SshConfig::class);

        $batchFile = tempnam(sys_get_temp_dir(), 'phalanx-sftp-batch-') ?: sys_get_temp_dir() . '/phalanx-sftp-batch-' . bin2hex(random_bytes(8));
        file_put_contents($batchFile, "get {$this->remotePath} {$this->localPath}\n");

        $scope->onDispose(static function () use ($batchFile): void {
            if (file_exists($batchFile)) {
                unlink($batchFile);
            }
        });

        $args = ['-b', $batchFile, ...$this->credential->toSftpArgs($config)];
        $cmdLine = ProcessAwaiter::buildCommandLine($config->sftpBinaryPath, $args);

        [$exitCode, , , $durationMs] = ProcessAwaiter::spawn($cmdLine, $scope);

        if ($exitCode !== 0) {
            throw new SshException("SFTP download failed (exit {$exitCode})", $exitCode);
        }

        $bytes = file_exists($this->localPath) ? (int) filesize($this->localPath) : 0;

        return new TransferResult(
            localPath: $this->localPath,
            remotePath: $this->remotePath,
            bytesTransferred: $bytes,
            durationMs: $durationMs,
        );
    }
}
