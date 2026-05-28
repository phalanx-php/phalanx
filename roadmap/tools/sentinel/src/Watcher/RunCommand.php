<?php

declare(strict_types=1);

namespace Sentinel\Watcher;

use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use React\ChildProcess\Process;
use React\EventLoop\Loop;
use React\Promise\Deferred;

/**
 * Async one-shot child process. Wraps `react/child-process` so the calling
 * fiber suspends until the process exits, replacing blocking `exec()` calls.
 *
 * Output beyond the configured byte cap is silently truncated to keep memory
 * bounded for chatty subprocesses (e.g. `git diff` on a large file).
 */
final readonly class RunCommand implements Executable
{
    public function __construct(
        private string $command,
        private ?string $cwd = null,
        private int $maxBytes = 1_048_576,
    ) {}

    public function __invoke(ExecutionScope $scope): string
    {
        $process = new Process($this->command, $this->cwd);
        $process->start(Loop::get());

        $stdout = '';
        $bytesWritten = 0;
        $maxBytes = $this->maxBytes;

        $process->stdout->on('data', static function (string $chunk) use (&$stdout, &$bytesWritten, $maxBytes): void {
            $remaining = $maxBytes - $bytesWritten;
            if ($remaining <= 0) {
                return;
            }

            if (strlen($chunk) > $remaining) {
                $stdout .= substr($chunk, 0, $remaining);
                $bytesWritten = $maxBytes;
                return;
            }

            $stdout .= $chunk;
            $bytesWritten += strlen($chunk);
        });

        $deferred = new Deferred(static function () use ($process): void {
            if ($process->isRunning()) {
                $process->terminate();
            }
        });

        $process->on('exit', static function () use ($deferred, &$stdout): void {
            $deferred->resolve($stdout);
        });

        $scope->onDispose(static function () use ($process): void {
            if ($process->isRunning()) {
                $process->terminate();
            }
        });

        return $scope->await($deferred->promise());
    }
}
