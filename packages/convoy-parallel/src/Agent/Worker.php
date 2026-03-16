<?php

declare(strict_types=1);

namespace Convoy\Parallel\Agent;

use Convoy\Parallel\Process\ProcessConfig;
use Convoy\Parallel\Process\ProcessHandle;
use Convoy\Parallel\Process\ProcessState;
use Convoy\Parallel\Protocol\ServiceCall;
use Convoy\Parallel\Protocol\TaskRequest;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

final class Worker
{
    private(set) AgentState $state = AgentState::Idle;

    private readonly Mailbox $mailbox;
    private readonly ProcessHandle $process;
    private readonly string $id;
    private bool $tickScheduled = false;
    private ?TimerInterface $drainPollTimer = null;

    /** @var callable(ServiceCall): PromiseInterface */
    private $serviceHandler;

    public function __construct(
        ProcessConfig $config,
        private readonly LoopInterface $loop,
        int $mailboxLimit = 100,
        ?string $id = null,
    ) {
        $this->id = $id ?? bin2hex(random_bytes(4));
        $this->mailbox = new Mailbox($mailboxLimit);
        $this->process = new ProcessHandle($config, $loop);
        $this->serviceHandler = static fn() => reject(new \RuntimeException('No service handler'));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function mailboxSize(): int
    {
        return $this->mailbox->count();
    }

    /** @param callable(ServiceCall): PromiseInterface $handler */
    public function setServiceHandler(callable $handler): void
    {
        $this->serviceHandler = $handler;
        $this->process->setServiceHandler($handler);
    }

    public function send(TaskRequest $task): PromiseInterface
    {
        if ($this->state === AgentState::Crashed) {
            return reject(new \RuntimeException("Agent {$this->id} crashed"));
        }

        if ($this->state === AgentState::Draining) {
            return reject(new \RuntimeException("Agent {$this->id} is draining"));
        }

        if ($this->mailbox->isFull()) {
            return reject(new \OverflowException("Agent {$this->id} mailbox full"));
        }

        $deferred = new Deferred();
        $this->mailbox->enqueue($task, $deferred);
        $this->scheduleTick();

        return $deferred->promise();
    }

    public function drain(): PromiseInterface
    {
        if ($this->state === AgentState::Crashed) {
            return resolve(null);
        }

        $this->state = AgentState::Draining;

        if ($this->mailbox->isEmpty() && $this->process->isIdle()) {
            return $this->process->drain();
        }

        $deferred = new Deferred();

        $poll = function () use ($deferred): void {
            if ($this->state === AgentState::Crashed) {
                $this->cancelDrainPoll();
                $deferred->resolve(null);
                return;
            }

            if ($this->mailbox->isEmpty() && $this->process->isIdle()) {
                $this->cancelDrainPoll();
                $this->process->drain()->then(
                    static fn() => $deferred->resolve(null),
                    static fn($e) => $deferred->reject($e),
                );
            }
        };

        $this->drainPollTimer = $this->loop->addPeriodicTimer(0.01, $poll);

        return $deferred->promise();
    }

    public function kill(): void
    {
        $this->cancelDrainPoll();
        $this->state = AgentState::Crashed;
        $this->process->kill();
        $this->mailbox->rejectAll(new \RuntimeException("Agent {$this->id} killed"));
    }

    public function restart(): void
    {
        if ($this->state !== AgentState::Crashed) {
            return;
        }

        $this->state = AgentState::Idle;
        $this->process->setServiceHandler($this->serviceHandler);
        $this->scheduleTick();
    }

    private function cancelDrainPoll(): void
    {
        if ($this->drainPollTimer !== null) {
            $this->loop->cancelTimer($this->drainPollTimer);
            $this->drainPollTimer = null;
        }
    }

    private function scheduleTick(): void
    {
        if ($this->tickScheduled) {
            return;
        }

        $this->tickScheduled = true;
        $this->loop->futureTick(function (): void {
            $this->tickScheduled = false;
            $this->tick();
        });
    }

    private function tick(): void
    {
        if ($this->state === AgentState::Crashed || $this->state === AgentState::Draining) {
            return;
        }

        if ($this->mailbox->isEmpty()) {
            $this->state = AgentState::Idle;
            return;
        }

        if (!$this->process->isIdle()) {
            return;
        }

        if (!$this->process->isRunning()) {
            $this->process->start();
        }

        $this->state = AgentState::Processing;

        [$task, $deferred] = $this->mailbox->dequeue();

        $this->process->execute($task)->then(
            function (mixed $result) use ($deferred): void {
                $deferred->resolve($result);
                $this->onTaskComplete();
            },
            function (\Throwable $e) use ($deferred): void {
                $deferred->reject($e);

                if ($this->process->state() === ProcessState::Crashed) {
                    $this->onCrash();
                } else {
                    $this->onTaskComplete();
                }
            },
        );
    }

    private function onTaskComplete(): void
    {
        if ($this->state === AgentState::Draining) {
            return;
        }

        if ($this->mailbox->isEmpty()) {
            $this->state = AgentState::Idle;
        } else {
            $this->scheduleTick();
        }
    }

    private function onCrash(): void
    {
        $this->cancelDrainPoll();
        $this->state = AgentState::Crashed;
        $this->mailbox->rejectAll(new \RuntimeException("Agent {$this->id} crashed"));
    }
}
