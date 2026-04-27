<?php

declare(strict_types=1);

namespace Phalanx\Enigma\Support;

use Phalanx\Exception\CancelledException;
use Phalanx\ExecutionScope;
use React\ChildProcess\Process;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

final class ProcessAwaiter
{
    /** @return PromiseInterface<int> */
    public static function awaitExit(Process $process): PromiseInterface
    {
        $deferred = new Deferred();

        $process->on('exit', static function (?int $code) use ($deferred): void {
            $deferred->resolve($code ?? 1);
        });

        return $deferred->promise();
    }

    /**
     * Spawn a process, collect stdout/stderr, await exit with cancellation support.
     *
     * @return array{int, string, string, float} [exitCode, stdout, stderr, durationMs]
     */
    public static function spawn(string $cmdLine, ExecutionScope $scope): array
    {
        $start = hrtime(true);
        $stdout = '';
        $stderr = '';

        $process = new Process($cmdLine);
        $process->start();

        $process->stdout?->on('data', static function (string $chunk) use (&$stdout): void {
            $stdout .= $chunk;
        });

        $process->stderr?->on('data', static function (string $chunk) use (&$stderr): void {
            $stderr .= $chunk;
        });

        try {
            $exitCode = $scope->await(self::awaitExit($process));
        } catch (CancelledException $e) {
            if ($process->isRunning()) {
                $process->terminate();
            }
            throw $e;
        }

        $durationMs = (hrtime(true) - $start) / 1_000_000;

        return [$exitCode, $stdout, $stderr, $durationMs];
    }

    /**
     * Build a shell command line from a binary path and argument list.
     *
     * @param list<string> $args
     */
    public static function buildCommandLine(string $binary, array $args): string
    {
        return escapeshellarg($binary)
            . ' '
            . implode(' ', array_map(escapeshellarg(...), $args));
    }
}
