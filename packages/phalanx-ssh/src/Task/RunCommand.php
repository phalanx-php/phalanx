<?php

declare(strict_types=1);

namespace Phalanx\Ssh\Task;

use Phalanx\ExecutionScope;
use Phalanx\Ssh\CommandResult;
use Phalanx\Ssh\Exception\SshConnectionException;
use Phalanx\Ssh\Exception\SshException;
use Phalanx\Ssh\SshConfig;
use Phalanx\Ssh\SshCredential;
use Phalanx\Ssh\Support\ProcessAwaiter;
use Phalanx\Task\Executable;
use Phalanx\Task\HasTimeout;

final class RunCommand implements Executable, HasTimeout
{
    public float $timeout {
        get => $this->timeoutSeconds ?? 0.0;
    }

    public function __construct(
        private readonly SshCredential $credential,
        private readonly string $command,
        private readonly ?float $timeoutSeconds = null,
    ) {}

    public function __invoke(ExecutionScope $scope): CommandResult
    {
        /** @var SshConfig $config */
        $config = $scope->service(SshConfig::class);
        $cmdLine = self::buildCommandLine($config, $this->credential, $this->command);

        [$exitCode, $stdout, $stderr, $durationMs] = ProcessAwaiter::spawn($cmdLine, $scope);

        $result = new CommandResult(
            exitCode: $exitCode,
            stdout: $stdout,
            stderr: $stderr,
            durationMs: $durationMs,
        );

        if ($exitCode === 255) {
            self::throwConnectionError($stderr, $result);
        }

        return $result;
    }

    private static function buildCommandLine(
        SshConfig $config,
        SshCredential $credential,
        string $command,
    ): string {
        $args = $credential->toConnectionArgs($config);
        $args[] = '--';
        $args[] = $command;

        return ProcessAwaiter::buildCommandLine($config->sshBinaryPath, $args);
    }

    private static function throwConnectionError(string $stderr, CommandResult $result): never
    {
        $patterns = [
            'Connection refused',
            'Connection timed out',
            'Permission denied',
            'Host key verification failed',
            'No route to host',
            'Could not resolve hostname',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($stderr, $pattern)) {
                throw new SshConnectionException(
                    "SSH connection failed: {$pattern}",
                    $result->exitCode,
                    $stderr,
                );
            }
        }

        throw new SshException(
            "SSH command failed (exit 255): {$stderr}",
            255,
            $stderr,
        );
    }
}
