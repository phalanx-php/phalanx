<?php

declare(strict_types=1);

namespace Phalanx\Skopos;

use Phalanx\Skopos\Output\Multiplexer;
use React\ChildProcess\Process as ChildProcess;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

final class ManagedProcess
{
    public private(set) Process $config;
    public private(set) ProcessState $state = ProcessState::Starting;
    public private(set) ?int $pid = null;

    public bool $isReady {
        get => $this->state === ProcessState::Ready || $this->state === ProcessState::Running;
    }

    private ?ChildProcess $process = null;
    /** @var Deferred<null>|null */
    private ?Deferred $readinessDeferred = null;
    private ?TimerInterface $gracefulTimer = null;
    private ?TimerInterface $forceTimer = null;
    /** @var list<\Closure(): void> */
    private array $crashCallbacks = [];
    /** @var list<\Closure(string): void> */
    private array $outputCallbacks = [];
    // Tracks whether stop() was intentionally called so onExit() does not fire crash callbacks.
    private bool $stopping = false;

    public function __construct(Process $config)
    {
        $this->config = $config;
    }

    public function start(Multiplexer $output): void
    {
        $this->state = ProcessState::Starting;
        $this->readinessDeferred = new Deferred();

        $env = $this->config->env !== [] ? $this->config->env : null;
        // exec: replaces the shell wrapper so $process->getPid() returns the real PID
        // and signals reach the actual command, not just the shell.
        $command = PHP_OS_FAMILY !== 'Windows'
            ? 'exec ' . $this->config->command
            : $this->config->command;
        $process = new ChildProcess($command, $this->config->cwd, $env);
        $process->start(Loop::get());
        $this->process = $process;

        $this->pid = $process->getPid();

        if ($this->config->readinessProbe->isImmediate()) {
            $this->transitionReady();
        }

        /** @var \React\Stream\ReadableStreamInterface|null $stdout */
        $stdout = $process->stdout;
        /** @var \React\Stream\ReadableStreamInterface|null $stderr */
        $stderr = $process->stderr;

        if ($stdout !== null && $stderr !== null) {
            $output->attach($this->config->name, $stdout, $stderr);
        }

        $deferred = $this->readinessDeferred;

        if (!$this->config->readinessProbe->isImmediate() && $stdout !== null && $deferred !== null) {
            $this->watchReadiness($stdout, $this->config->readinessProbe, $deferred);
        }

        if ($this->config->reloadProbe !== null && $stdout !== null) {
            $this->watchReloadProbe($stdout, $this->config->reloadProbe, $this->outputCallbacks);
        }

        // Non-static: calls $this->onExit() to handle state transition and readiness rejection.
        // Cycle is bounded — 'exit' fires exactly once, after which $this->process is a dead handle.
        $process->on('exit', function (?int $code, mixed $signal): void {
            $this->onExit($code);
        });
    }

    public function onCrash(\Closure $callback): void
    {
        $this->crashCallbacks[] = $callback;
    }

    /** @param \Closure(string): void $callback */
    public function onOutput(\Closure $callback): void
    {
        $this->outputCallbacks[] = $callback;
    }

    /** @return PromiseInterface<null> */
    public function readiness(): PromiseInterface
    {
        if ($this->readinessDeferred === null) {
            return resolve(null);
        }

        return $this->readinessDeferred->promise();
    }

    /** @return PromiseInterface<null> */
    public function restart(Multiplexer $output): PromiseInterface
    {
        $this->stopping = true;

        return $this->stop(gracefulTimeout: 2.0, forceTimeout: 5.0)->then(function () use ($output): null {
            $this->stopping = false;
            $this->start($output);
            return null;
        });
    }

    /** @return PromiseInterface<null> */
    public function stop(float $gracefulTimeout = 5.0, float $forceTimeout = 10.0): PromiseInterface
    {
        if ($this->process === null || !$this->process->isRunning()) {
            $this->state = ProcessState::Stopped;
            return resolve(null);
        }

        $this->stopping = true;

        /** @var Deferred<null> $deferred */
        $deferred = new Deferred(static function (): void {
        });

        $process = $this->process;

        // Non-static: resolves $deferred on exit, accesses $this->cancelStopTimers().
        // Cycle is bounded — fires exactly once on process exit.
        $process->on('exit', function () use ($deferred): void {
            $this->cancelStopTimers();
            $this->state = ProcessState::Stopped;
            $deferred->resolve(null);
        });

        $process->terminate(\SIGTERM);

        $this->gracefulTimer = Loop::addTimer($gracefulTimeout, static function () use ($process): void {
            if ($process->isRunning()) {
                $process->terminate(\SIGTERM);
            }
        });

        $this->forceTimer = Loop::addTimer($forceTimeout, static function () use ($process): void {
            if ($process->isRunning()) {
                $process->terminate(\SIGKILL);
            }
        });

        return $deferred->promise();
    }

    /** @param Deferred<null> $deferred */
    private function watchReadiness(
        \React\Stream\ReadableStreamInterface $stdout,
        ReadinessProbe $probe,
        Deferred $deferred,
    ): void {
        $resolved = false;

        $stdout->on('data', static function (string $chunk) use ($probe, $deferred, &$resolved): void {
            if ($resolved) {
                return;
            }

            foreach (explode("\n", $chunk) as $line) {
                if ($probe->matches($line)) {
                    $resolved = true;
                    $deferred->resolve(null);
                    return;
                }
            }
        });
    }

    /** @param list<\Closure(string): void> $callbacks */
    private function watchReloadProbe(
        \React\Stream\ReadableStreamInterface $stdout,
        ReadinessProbe $probe,
        array &$callbacks,
    ): void {
        $stdout->on('data', static function (string $chunk) use ($probe, &$callbacks): void {
            foreach (explode("\n", $chunk) as $line) {
                if ($probe->matches($line)) {
                    foreach ($callbacks as $callback) {
                        $callback($line);
                    }
                    return;
                }
            }
        });
    }

    private function transitionReady(): void
    {
        $this->state = ProcessState::Ready;

        $deferred = $this->readinessDeferred;
        $this->readinessDeferred = null;

        Loop::futureTick(static function () use ($deferred): void {
            $deferred?->resolve(null);
        });

        $this->state = ProcessState::Running;
    }

    private function onExit(?int $code): void
    {
        $this->cancelStopTimers();
        $this->pid = null;

        $pendingDeferred = $this->readinessDeferred;
        $this->readinessDeferred = null;

        if ($pendingDeferred !== null) {
            $pendingDeferred->reject(
                new \RuntimeException(
                    "Process '{$this->config->name}' exited (code {$code}) before becoming ready"
                )
            );
        }

        if ($this->state !== ProcessState::Stopped) {
            $this->state = ($code === 0 || $this->stopping) ? ProcessState::Stopped : ProcessState::Crashed;
        }

        if ($this->state === ProcessState::Crashed && !$this->stopping) {
            foreach ($this->crashCallbacks as $callback) {
                $callback();
            }
            $this->crashCallbacks = [];
        }
    }

    private function cancelStopTimers(): void
    {
        if ($this->gracefulTimer !== null) {
            Loop::cancelTimer($this->gracefulTimer);
            $this->gracefulTimer = null;
        }

        if ($this->forceTimer !== null) {
            Loop::cancelTimer($this->forceTimer);
            $this->forceTimer = null;
        }
    }
}
