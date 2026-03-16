<?php

declare(strict_types=1);

namespace Convoy\Parallel\Process;

use Convoy\Parallel\Protocol\Codec;
use Convoy\Parallel\Protocol\MessageType;
use Convoy\Parallel\Protocol\Response;
use Convoy\Parallel\Protocol\ServiceCall;
use Convoy\Parallel\Protocol\TaskRequest;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

final class ProcessHandle
{
    private ?Process $process = null;
    private string $buffer = '';
    private ?Deferred $pendingTask = null;
    private ?string $pendingTaskId = null;
    private ProcessState $state = ProcessState::Idle;
    private bool $drainInProgress = false;
    private ?TimerInterface $gracefulTimer = null;
    private ?TimerInterface $forceTimer = null;

    /** @var array<string, Deferred> */
    private array $pendingServiceCalls = [];

    /** @var callable(ServiceCall): PromiseInterface */
    private $serviceHandler;

    public function __construct(
        private readonly ProcessConfig $config,
        private readonly LoopInterface $loop,
    ) {
        $this->serviceHandler = static fn() => reject(new \RuntimeException('No service handler'));
    }

    public function state(): ProcessState
    {
        return $this->state;
    }

    public function isIdle(): bool
    {
        return $this->state === ProcessState::Idle;
    }

    public function isRunning(): bool
    {
        return $this->process !== null && $this->process->isRunning();
    }

    /** @param callable(ServiceCall): PromiseInterface $handler */
    public function setServiceHandler(callable $handler): void
    {
        $this->serviceHandler = $handler;
    }

    public function start(): void
    {
        if ($this->process !== null && $this->process->isRunning()) {
            return;
        }

        $this->cleanup();

        $cmd = sprintf(
            'exec php %s --autoload=%s',
            escapeshellarg($this->config->workerScript),
            escapeshellarg($this->config->autoloadPath),
        );

        $this->process = new Process($cmd);
        $this->process->start($this->loop);
        $this->state = ProcessState::Idle;
        $this->buffer = '';

        $this->process->stdout->on('data', function (string $data): void {
            $this->buffer .= $data;
            $this->processBuffer();
        });

        $this->process->stderr->on('data', function (string $data): void {
            error_log("[Worker STDERR] $data");
        });

        $this->process->on('exit', function (?int $code, $signal): void {
            $this->onExit($code);
        });
    }

    public function execute(TaskRequest $task): PromiseInterface
    {
        if ($this->state !== ProcessState::Idle) {
            return reject(new \RuntimeException("Process not idle: {$this->state->name}"));
        }

        if ($this->process === null || !$this->process->isRunning()) {
            return reject(new \RuntimeException('Process not running'));
        }

        $this->state = ProcessState::Busy;
        $this->pendingTaskId = $task->id;
        $this->pendingTask = new Deferred();

        $this->process->stdin->write(Codec::encode($task));

        return $this->pendingTask->promise()->finally(function (): void {
            if ($this->state === ProcessState::Busy) {
                $this->state = ProcessState::Idle;
            }
        });
    }

    public function drain(): PromiseInterface
    {
        if ($this->process === null || !$this->process->isRunning()) {
            return resolve(null);
        }

        if ($this->drainInProgress) {
            return resolve(null);
        }

        $this->drainInProgress = true;
        $this->state = ProcessState::Draining;
        $deferred = new Deferred();

        $this->process->on('exit', function () use ($deferred): void {
            $this->cancelDrainTimers();
            $deferred->resolve(null);
        });

        $this->process->stdin->end();

        $this->gracefulTimer = $this->loop->addTimer($this->config->gracefulTimeout, function (): void {
            $this->gracefulTimer = null;
            if ($this->process?->isRunning()) {
                $this->process->terminate(SIGTERM);
            }
        });

        $this->forceTimer = $this->loop->addTimer($this->config->forceTimeout, function (): void {
            $this->forceTimer = null;
            if ($this->process?->isRunning()) {
                $this->process->terminate(SIGKILL);
            }
        });

        return $deferred->promise();
    }

    public function kill(): void
    {
        $this->cancelDrainTimers();

        if ($this->process?->isRunning()) {
            $this->process->terminate(SIGKILL);
        }

        $this->state = ProcessState::Crashed;
        $this->cleanup();
    }

    private function onExit(?int $code): void
    {
        $this->cancelDrainTimers();

        if ($this->state !== ProcessState::Draining) {
            $this->state = ProcessState::Crashed;
        }

        if ($this->pendingTask !== null) {
            $this->pendingTask->reject(
                new \RuntimeException("Worker exited with code $code")
            );
            $this->pendingTask = null;
            $this->pendingTaskId = null;
        }

        foreach ($this->pendingServiceCalls as $deferred) {
            $deferred->reject(new \RuntimeException('Worker exited'));
        }
        $this->pendingServiceCalls = [];
    }

    private function cancelDrainTimers(): void
    {
        if ($this->gracefulTimer !== null) {
            $this->loop->cancelTimer($this->gracefulTimer);
            $this->gracefulTimer = null;
        }

        if ($this->forceTimer !== null) {
            $this->loop->cancelTimer($this->forceTimer);
            $this->forceTimer = null;
        }
    }

    private function cleanup(): void
    {
        $this->cancelDrainTimers();
        $this->drainInProgress = false;
        $this->buffer = '';
        $this->process = null;
    }

    private function processBuffer(): void
    {
        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 1);

            if (trim($line) === '') {
                continue;
            }

            try {
                $message = Codec::decode($line);
                $this->handleMessage($message);
            } catch (\Throwable $e) {
                error_log("[Worker] Failed to decode message: {$e->getMessage()}");
            }
        }
    }

    private function handleMessage(TaskRequest|ServiceCall|Response $message): void
    {
        if ($message instanceof ServiceCall) {
            $this->handleServiceCall($message);
            return;
        }

        if ($message instanceof Response) {
            if ($message->type === MessageType::ServiceResponse) {
                $this->handleServiceResponse($message);
                return;
            }

            if ($message->type === MessageType::TaskResponse) {
                $this->handleTaskResponse($message);
                return;
            }
        }
    }

    private function handleServiceCall(ServiceCall $call): void
    {
        $handler = $this->serviceHandler;
        $handler($call)->then(
            function (mixed $result) use ($call): void {
                $response = Response::serviceOk($call->id, $result);
                $this->process?->stdin->write(Codec::encode($response));
            },
            function (\Throwable $e) use ($call): void {
                $response = Response::serviceErr($call->id, $e);
                $this->process?->stdin->write(Codec::encode($response));
            },
        );
    }

    private function handleServiceResponse(Response $response): void
    {
        $deferred = $this->pendingServiceCalls[$response->id] ?? null;

        if ($deferred === null) {
            return;
        }

        unset($this->pendingServiceCalls[$response->id]);

        if ($response->ok) {
            $deferred->resolve($response->result);
        } else {
            $deferred->reject(new \RuntimeException($response->errorMessage ?? 'Service call failed'));
        }
    }

    private function handleTaskResponse(Response $response): void
    {
        if ($this->pendingTask === null || $this->pendingTaskId !== $response->id) {
            return;
        }

        $deferred = $this->pendingTask;
        $this->pendingTask = null;
        $this->pendingTaskId = null;

        if ($response->ok) {
            $deferred->resolve($response->result);
        } else {
            $deferred->reject(new \RuntimeException($response->errorMessage ?? 'Task failed'));
        }
    }
}
