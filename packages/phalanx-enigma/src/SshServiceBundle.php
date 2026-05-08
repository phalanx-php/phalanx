<?php

declare(strict_types=1);

namespace Phalanx\Enigma;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class SshServiceBundle extends ServiceBundle
{
    public function services(Services $services, AppContext $context): void
    {
        $services->config(SshConfig::class, static fn(AppContext $ctx): SshConfig => new SshConfig(
            sshBinaryPath: $ctx->string('SSH_BINARY_PATH', 'ssh'),
            scpBinaryPath: $ctx->string('SCP_BINARY_PATH', 'scp'),
            sftpBinaryPath: $ctx->string('SFTP_BINARY_PATH', 'sftp'),
            defaultTimeoutSeconds: $ctx->float('SSH_DEFAULT_TIMEOUT', 30.0),
            connectionTimeoutSeconds: $ctx->float('SSH_CONNECTION_TIMEOUT', 10.0),
            strictHostKeyChecking: $ctx->bool('SSH_STRICT_HOST_KEY_CHECKING', true),
        ));
    }
}
