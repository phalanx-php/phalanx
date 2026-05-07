<?php

declare(strict_types=1);

namespace Phalanx\Enigma\Task;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Enigma\Exception\SshConnectionException;
use Phalanx\Enigma\Exception\SshTimeoutException;
use Phalanx\Enigma\SshConfig;
use Phalanx\Enigma\SshCredential;
use Phalanx\Enigma\Support\ProcessAwaiter;
use Phalanx\Enigma\TunnelDirection;
use Phalanx\Enigma\TunnelHandle;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use Phalanx\System\StreamingProcess;
use Phalanx\System\StreamingProcessHandle;
use Phalanx\System\TcpClient;
use Phalanx\Task\Executable;
use Throwable;

final class OpenTunnel implements Executable
{
    public function __construct(
        private readonly SshCredential $credential,
        private readonly int $localPort,
        private readonly string $remoteHost,
        private readonly int $remotePort,
        private readonly TunnelDirection $direction = TunnelDirection::Local,
        private readonly ?SshCredential $targetCredential = null,
    ) {
    }

    public function __invoke(ExecutionScope $scope): TunnelHandle
    {
        /** @var SshConfig $config */
        $config = $scope->service(SshConfig::class);

        $flag = match ($this->direction) {
            TunnelDirection::Local => '-L',
            TunnelDirection::Remote => '-R',
        };

        $tunnelSpec = "{$this->localPort}:{$this->remoteHost}:{$this->remotePort}";

        $args = [
            '-N',
            '-o', 'ExitOnForwardFailure=yes',
            $flag, $tunnelSpec,
            ...$this->credential->toConnectionArgs($config),
        ];

        $process = StreamingProcess::command(ProcessAwaiter::argv($config->sshBinaryPath, $args))->start($scope);

        try {
            self::waitForTunnel(
                process: $process,
                scope: $scope,
                direction: $this->direction,
                localPort: $this->localPort,
                timeout: $config->connectionTimeoutSeconds,
            );
        } catch (Cancelled $e) {
            $process->kill();
            throw $e;
        } catch (Throwable $e) {
            $process->kill();
            throw $e;
        }

        $handle = new TunnelHandle(
            localPort: $this->localPort,
            remoteHost: $this->remoteHost,
            remotePort: $this->remotePort,
            direction: $this->direction,
            targetCredential: $this->targetCredential,
            process: $process,
            scope: $scope,
        );

        $scope->onDispose(static fn() => $handle->close());

        return $handle;
    }

    private static function waitForTunnel(
        StreamingProcessHandle $process,
        TaskScope&TaskExecutor $scope,
        TunnelDirection $direction,
        int $localPort,
        float $timeout,
    ): void {
        $stderr = '';
        $readyAt = microtime(true) + 0.5;
        $deadline = microtime(true) + max(0.0, $timeout);

        while (true) {
            $scope->throwIfCancelled();
            $stderr .= $process->getIncrementalErrorOutput();

            if (!$process->isRunning()) {
                $process->close('enigma.tunnel.failed');
                throw new SshConnectionException(
                    "Failed to establish SSH tunnel: {$stderr}",
                    stderr: $stderr,
                );
            }

            if ($direction === TunnelDirection::Local && self::localPortAccepts($scope, $localPort)) {
                return;
            }

            if ($direction === TunnelDirection::Remote && microtime(true) >= $readyAt) {
                return;
            }

            if (microtime(true) >= $deadline) {
                $process->kill();
                throw new SshTimeoutException(
                    sprintf('SSH tunnel timed out after %.3f seconds', $timeout),
                    stderr: $stderr,
                );
            }

            $scope->delay(0.01);
        }
    }

    private static function localPortAccepts(TaskScope&TaskExecutor $scope, int $localPort): bool
    {
        $client = new TcpClient();

        try {
            return $client->connect($scope, '127.0.0.1', $localPort, 0.02);
        } finally {
            $client->close();
        }
    }
}
