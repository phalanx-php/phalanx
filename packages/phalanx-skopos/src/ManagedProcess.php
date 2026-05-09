<?php

declare(strict_types=1);

namespace Phalanx\Skopos;

use Closure;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Skopos\Output\Multiplexer;
use Phalanx\System\StreamingProcess;
use Phalanx\System\StreamingProcessHandle;

/**
 * One supervised dev process. Wraps a StreamingProcessHandle and runs a
 * single drain coroutine per start() that:
 *
 *   - copies stdout/stderr increments into the Multiplexer
 *   - matches the readiness probe to flip Starting -> Running
 *   - detects exit, transitions to Stopped or Crashed, fires onCrash
 *
 * Cleanup is scope-owned: StreamingProcess::start() registers an onDispose
 * close on the supplied scope, so abandoned ManagedProcesses are reaped
 * when the scope tears down even if stop() is never called.
 */
final class ManagedProcess
{
    private(set) Process $config;

    private(set) ProcessState $state = ProcessState::Starting;

    private(set) ?int $pid = null;

    public bool $isReady {
        get => $this->state === ProcessState::Running;
    }

    private ?StreamingProcessHandle $handle = null;

    /** @var list<Closure(): void> */
    private array $crashCallbacks = [];

    private bool $stopping = false;

    public function __construct(Process $config)
    {
        $this->config = $config;
    }

    public function start(ExecutionScope $scope, Multiplexer $output): void
    {
        $this->state = ProcessState::Starting;
        $this->stopping = false;

        $argv = self::buildArgv($this->config->command);
        $env = $this->config->env !== [] ? $this->config->env : null;

        $process = StreamingProcess::command($argv);
        if ($this->config->cwd !== null) {
            $process = $process->withCwd($this->config->cwd);
        }
        if ($env !== null) {
            $process = $process->withEnv($env);
        }

        $handle = $process->start($scope);
        $this->handle = $handle;
        $this->pid = $handle->pid();

        if ($this->config->readinessProbe->isImmediate()) {
            $this->state = ProcessState::Running;
        }

        $self = $this;
        $name = $this->config->name;
        $readinessProbe = $this->config->readinessProbe;

        $scope->go(static function () use ($self, $scope, $handle, $output, $name, $readinessProbe): void {
            $lineBuffer = '';
            $scanReadiness = static function (string $chunk) use ($self, $readinessProbe, &$lineBuffer): void {
                $lineBuffer .= $chunk;

                while (($pos = strpos($lineBuffer, "\n")) !== false) {
                    $line = substr($lineBuffer, 0, $pos);
                    $lineBuffer = substr($lineBuffer, $pos + 1);

                    if (
                        !$readinessProbe->isImmediate()
                        && $self->state === ProcessState::Starting
                        && $readinessProbe->matches($line)
                    ) {
                        $self->state = ProcessState::Running;
                    }
                }
            };

            while ($handle->isRunning() && !$scope->isCancelled) {
                $stdoutChunk = $handle->getIncrementalOutput();
                $stderrChunk = $handle->getIncrementalErrorOutput();

                if ($stdoutChunk !== '') {
                    $output->writeOutput($name, $stdoutChunk);
                    $scanReadiness($stdoutChunk);
                }

                if ($stderrChunk !== '') {
                    $output->writeError($name, $stderrChunk);
                    $scanReadiness($stderrChunk);
                }

                $scope->delay(0.02);
            }

            $stdoutChunk = $handle->getIncrementalOutput();
            if ($stdoutChunk !== '') {
                $output->writeOutput($name, $stdoutChunk);
                $scanReadiness($stdoutChunk);
            }
            $stderrChunk = $handle->getIncrementalErrorOutput();
            if ($stderrChunk !== '') {
                $output->writeError($name, $stderrChunk);
                $scanReadiness($stderrChunk);
            }
            $output->flush($name);

            $self->onProcessExit($handle);
        }, name: 'skopos.drain.' . $name);
    }

    public function onCrash(Closure $callback): void
    {
        $this->crashCallbacks[] = $callback;
    }

    public function waitUntilReady(ExecutionScope $scope, float $timeout = 30.0): void
    {
        $deadline = microtime(true) + $timeout;

        while ($this->state === ProcessState::Starting) {
            $scope->throwIfCancelled();

            if (microtime(true) >= $deadline) {
                throw new \RuntimeException(
                    "Process '{$this->config->name}' did not become ready within {$timeout}s"
                );
            }

            $scope->delay(0.02);
        }

        if ($this->state === ProcessState::Crashed || $this->state === ProcessState::Stopped) {
            throw new \RuntimeException(
                "Process '{$this->config->name}' exited before becoming ready (state: {$this->state->name})"
            );
        }
    }

    public function restart(ExecutionScope $scope, Multiplexer $output): void
    {
        $this->stop();
        $this->start($scope, $output);
    }

    public function stop(float $gracefulTimeout = 2.0, float $forceTimeout = 5.0): void
    {
        $handle = $this->handle;
        if ($handle === null) {
            $this->state = ProcessState::Stopped;
            return;
        }

        $this->stopping = true;
        $this->handle = null;

        if ($handle->isRunning()) {
            $handle->stop($gracefulTimeout, $forceTimeout);
        } else {
            $handle->close('skopos.stop');
        }
    }

    /**
     * Internal: invoked by the drain coroutine when the underlying handle
     * stops running. Public-but-internal because the closure cannot reach
     * a private method on $self after PHP's scope binding rules apply to
     * static closures.
     */
    public function onProcessExit(StreamingProcessHandle $handle): void
    {
        if ($handle !== $this->handle && $this->handle !== null) {
            // A newer handle has taken over — old drain coroutine for a
            // restarted process is exiting. Don't transition state.
            return;
        }

        $this->pid = null;
        $wasStopping = $this->stopping;

        if ($this->state !== ProcessState::Stopped) {
            $this->state = $wasStopping ? ProcessState::Stopped : ProcessState::Crashed;
        }

        if (!$wasStopping && $this->state === ProcessState::Crashed) {
            $callbacks = $this->crashCallbacks;
            $this->crashCallbacks = [];
            foreach ($callbacks as $cb) {
                $cb();
            }
        }

        $this->handle = null;
    }

    /** @return non-empty-list<string> */
    private static function buildArgv(string $command): array
    {
        $trimmed = trim($command);
        if ($trimmed === '') {
            throw new \RuntimeException('Skopos: empty command');
        }

        return ['sh', '-c', 'exec ' . $trimmed];
    }
}
