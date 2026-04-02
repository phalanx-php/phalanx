<?php

declare(strict_types=1);

namespace Phalanx\Ssh\Task;

use Phalanx\ExecutionScope;
use Phalanx\Ssh\SshCredential;
use Phalanx\Ssh\TunnelDirection;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

final class TunnelThrough implements Executable
{
    /**
     * @param SshCredential         $bastion           Bastion/jump host credentials
     * @param string                $targetHost        The actual target behind the bastion
     * @param int                   $targetPort        SSH port on the target (usually 22)
     * @param int                   $localPort         Local port to bind the tunnel to
     * @param Scopeable|Executable  $innerTask         Task to execute through the tunnel
     * @param SshCredential|null    $targetCredential  Credentials for the target host
     */
    public function __construct(
        private readonly SshCredential $bastion,
        private readonly string $targetHost,
        private readonly int $targetPort,
        private readonly int $localPort,
        private readonly Scopeable|Executable $innerTask,
        private readonly ?SshCredential $targetCredential = null,
    ) {}

    public function __invoke(ExecutionScope $scope): mixed
    {
        $tunnel = $scope->execute(new OpenTunnel(
            credential: $this->bastion,
            localPort: $this->localPort,
            remoteHost: $this->targetHost,
            remotePort: $this->targetPort,
            direction: TunnelDirection::Local,
            targetCredential: $this->targetCredential,
        ));

        try {
            return $tunnel->execute($this->innerTask);
        } finally {
            $tunnel->close();
        }
    }
}
