<?php

declare(strict_types=1);

namespace Phalanx\Ssh;

use Phalanx\Ssh\Exception\SshConnectionException;
use Phalanx\Ssh\Task\RunCommand;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\TaskScope;
use React\ChildProcess\Process;

final class TunnelHandle implements \Stringable
{
    private(set) bool $closed = false;

    public bool $isAlive {
        get => !$this->closed && $this->process->isRunning();
    }

    public function __construct(
        public readonly int $localPort,
        public readonly string $remoteHost,
        public readonly int $remotePort,
        public readonly TunnelDirection $direction,
        public readonly ?SshCredential $targetCredential,
        private readonly Process $process,
        private readonly TaskScope $scope,
    ) {}

    public function execute(Scopeable|Executable $task): mixed
    {
        if (!$this->isAlive) {
            throw new SshConnectionException('Tunnel is not alive');
        }

        return $this->scope->execute($task);
    }

    public function run(string $command, ?SshCredential $credential = null): CommandResult
    {
        $cred = $credential ?? $this->tunneledCredential();

        return $this->execute(new RunCommand(
            credential: $cred,
            command: $command,
        ));
    }

    public function tunneledCredential(): SshCredential
    {
        $target = $this->targetCredential;

        return new SshCredential(
            host: '127.0.0.1',
            port: $this->localPort,
            user: $target !== null ? $target->user : 'root',
            keyPath: $target?->keyPath,
        );
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->process->isRunning()) {
            $this->process->terminate();
        }
    }

    public function __toString(): string
    {
        $arrow = $this->direction === TunnelDirection::Local ? '->' : '<-';

        return "tunnel:{$this->localPort} {$arrow} {$this->remoteHost}:{$this->remotePort}";
    }
}
