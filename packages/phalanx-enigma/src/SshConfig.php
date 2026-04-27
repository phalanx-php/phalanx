<?php

declare(strict_types=1);

namespace Phalanx\Enigma;

final readonly class SshConfig
{
    public function __construct(
        public string $sshBinaryPath = 'ssh',
        public string $scpBinaryPath = 'scp',
        public string $sftpBinaryPath = 'sftp',
        public float $defaultTimeoutSeconds = 30.0,
        public float $connectionTimeoutSeconds = 10.0,
        public bool $strictHostKeyChecking = true,
    ) {}
}
