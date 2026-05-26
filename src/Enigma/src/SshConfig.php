<?php

declare(strict_types=1);

namespace Phalanx\Enigma;

use Phalanx\Themis\Config;
use Phalanx\Themis\Env;
use Phalanx\Themis\Issue;
use Phalanx\Themis\ValidationContext;

final class SshConfig implements Config
{
    public bool $configured {
        get => $this->sshBinaryPath !== '';
    }

    public function __construct(
        #[Env(key: 'SSH_BINARY_PATH', description: 'Path to the ssh binary')]
        private(set) string $sshBinaryPath = 'ssh',
        #[Env(key: 'SCP_BINARY_PATH', description: 'Path to the scp binary')]
        private(set) string $scpBinaryPath = 'scp',
        #[Env(key: 'SFTP_BINARY_PATH', description: 'Path to the sftp binary')]
        private(set) string $sftpBinaryPath = 'sftp',
        #[Env(key: 'SSH_DEFAULT_TIMEOUT', description: 'Default SSH operation timeout in seconds')]
        private(set) float $defaultTimeoutSeconds = 30.0,
        #[Env(key: 'SSH_CONNECTION_TIMEOUT', description: 'SSH connection establishment timeout in seconds')]
        private(set) float $connectionTimeoutSeconds = 10.0,
        #[Env(key: 'SSH_STRICT_HOST_KEY_CHECKING', description: 'Enforce strict host key verification')]
        private(set) bool $strictHostKeyChecking = true,
    ) {
    }

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array
    {
        return [];
    }
}
