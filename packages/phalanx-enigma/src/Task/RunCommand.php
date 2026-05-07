<?php

declare(strict_types=1);

namespace Phalanx\Enigma\Task;

use Phalanx\Enigma\CommandResult;
use Phalanx\Enigma\Exception\SshConnectionException;
use Phalanx\Enigma\Exception\SshException;
use Phalanx\Enigma\SshConfig;
use Phalanx\Enigma\SshCredential;
use Phalanx\Enigma\Support\ProcessAwaiter;
use Phalanx\Scope\ExecutionScope;
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
    ) {
    }

    public function __invoke(ExecutionScope $scope): CommandResult
    {
        /** @var SshConfig $config */
        $config = $scope->service(SshConfig::class);
        $argv = self::argv($config, $this->credential, $this->command);

        [$exitCode, $stdout, $stderr, $durationMs] = ProcessAwaiter::spawn(
            $argv,
            $scope,
            $this->timeoutSeconds ?? $config->defaultTimeoutSeconds,
        );

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

    /**
     * @return non-empty-list<string>
     */
    private static function argv(
        SshConfig $config,
        SshCredential $credential,
        string $command,
    ): array {
        $args = $credential->toConnectionArgs($config);
        $args[] = '--';
        $args[] = $command;

        return ProcessAwaiter::argv($config->sshBinaryPath, $args);
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
