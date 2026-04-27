<?php

declare(strict_types=1);

namespace Phalanx\Enigma\Task;

use Phalanx\Exception\CancelledException;
use Phalanx\ExecutionScope;
use Phalanx\Enigma\Exception\SshConnectionException;
use Phalanx\Enigma\SshConfig;
use Phalanx\Enigma\SshCredential;
use Phalanx\Enigma\Support\ProcessAwaiter;
use Phalanx\Enigma\TunnelDirection;
use Phalanx\Enigma\TunnelHandle;
use Phalanx\Task\Executable;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

final class OpenTunnel implements Executable
{
    public function __construct(
        private readonly SshCredential $credential,
        private readonly int $localPort,
        private readonly string $remoteHost,
        private readonly int $remotePort,
        private readonly TunnelDirection $direction = TunnelDirection::Local,
        private readonly ?SshCredential $targetCredential = null,
    ) {}

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

        $cmdLine = ProcessAwaiter::buildCommandLine($config->sshBinaryPath, $args);

        $process = new \React\ChildProcess\Process($cmdLine);
        $process->start();

        $stderr = '';
        $process->stderr?->on('data', static function (string $chunk) use (&$stderr): void {
            $stderr .= $chunk;
        });

        try {
            $established = $scope->await(
                self::awaitEstablished($process, $config->connectionTimeoutSeconds),
            );
        } catch (CancelledException $e) {
            if ($process->isRunning()) {
                $process->terminate();
            }
            throw $e;
        }

        if (!$established) {
            if ($process->isRunning()) {
                $process->terminate();
            }
            throw new SshConnectionException(
                "Failed to establish SSH tunnel: {$stderr}",
                stderr: $stderr,
            );
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

    /** @return PromiseInterface<bool> */
    private static function awaitEstablished(
        \React\ChildProcess\Process $process,
        float $timeout,
    ): PromiseInterface {
        $deferred = new Deferred();
        /** @var ?TimerInterface $timer */
        $timer = null;

        $process->on('exit', static function () use ($deferred, &$timer): void {
            if ($timer instanceof TimerInterface) {
                Loop::cancelTimer($timer);
            }
            $deferred->resolve(false);
        });

        $timer = Loop::addTimer(0.5, static function () use ($deferred, $process): void {
            if ($process->isRunning()) {
                $deferred->resolve(true);
            }
        });

        return \React\Promise\Timer\timeout($deferred->promise(), $timeout)
            ->catch(static fn() => false);
    }
}
