<?php

declare(strict_types=1);

namespace Phalanx\Ssh;

use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class SshServiceBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $services->config(SshConfig::class, static fn(array $ctx) => new SshConfig(
            sshBinaryPath: (string) ($ctx['SSH_BINARY_PATH'] ?? 'ssh'),
            scpBinaryPath: (string) ($ctx['SCP_BINARY_PATH'] ?? 'scp'),
            sftpBinaryPath: (string) ($ctx['SFTP_BINARY_PATH'] ?? 'sftp'),
            defaultTimeoutSeconds: (float) ($ctx['SSH_DEFAULT_TIMEOUT'] ?? 30.0),
            connectionTimeoutSeconds: (float) ($ctx['SSH_CONNECTION_TIMEOUT'] ?? 10.0),
            strictHostKeyChecking: (bool) ($ctx['SSH_STRICT_HOST_KEY_CHECKING'] ?? true),
        ));
    }
}
