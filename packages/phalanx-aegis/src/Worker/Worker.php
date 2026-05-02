<?php

declare(strict_types=1);

namespace Phalanx\Worker;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Worker\Protocol\Response;
use Phalanx\Worker\Protocol\TaskRequest;

/**
 * One process worker. Owns a ProcessHandle, a Mailbox, and reader/writer
 * coroutines that move messages between the mailbox and the child's pipes.
 *
 * Per-request flow:
 *  1. ParallelWorkerDispatch::dispatch() registers a pending Channel(1) keyed
 *     by request id, then pushes the TaskRequest onto the mailbox.
 *  2. Writer coroutine pops the mailbox and writes to child stdin.
 *  3. Reader coroutine reads child stdout, decodes the Response, and pushes
 *     it onto the matching pending channel.
 *  4. Caller pops the pending channel and gets back the response.
 */
class Worker
{
    private AgentState $state = AgentState::Idle;

    private int $readerCid = 0;

    private int $writerCid = 0;

    private bool $stopped = false;

    /** @var array<string, Channel> */
    private array $pending = [];

    public function __construct(
        public readonly ProcessHandle $process,
        public readonly Mailbox $mailbox,
    ) {
    }

    public function start(): void
    {
        $this->process->start();
        $this->writerCid = (int) Coroutine::create($this->writerLoop(...));
        $this->readerCid = (int) Coroutine::create($this->readerLoop(...));
    }

    /**
     * Submit a request and wait for its response. Caller must have ownership of
     * a CancellationToken; if it cancels, this returns Response::err with a
     * Cancelled.
     */
    public function submit(TaskRequest $req): Response
    {
        $waiter = new Channel(1);
        $this->pending[$req->id] = $waiter;
        $this->mailbox->push($req);
        $this->state = AgentState::Processing;
        try {
            $resp = $waiter->pop();
            if ($resp === false) {
                return Response::err($req->id, new WorkerCrashedException('worker pipe closed'));
            }
            return $resp;
        } finally {
            unset($this->pending[$req->id]);
            if ($this->pending === []) {
                $this->state = AgentState::Idle;
            }
            $waiter->close();
        }
    }

    /**
     * Abort an in-flight request by killing the worker process. The readerLoop
     * sees the closed pipe, marks the worker Crashed, and pushes a
     * `WorkerCrashedException` response to every pending waiter (which is what
     * unblocks `submit()`). Callers that want to surface the cancel as a
     * `Cancelled` should pre-stage it on the waiter before invoking this.
     *
     * Idempotent: kill on an already-dead process is a no-op.
     */
    public function abortInFlight(string $reqId): void
    {
        $waiter = $this->pending[$reqId] ?? null;
        if ($waiter !== null) {
            $waiter->push(Response::err($reqId, new Cancelled('inWorker cancelled')));
        }
        $this->state = AgentState::Crashed;
        $this->process->kill(SIGTERM);
    }

    public function depth(): int
    {
        return $this->mailbox->depth() + count($this->pending);
    }

    public function state(): AgentState
    {
        return $this->state;
    }

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;
        $this->state = AgentState::Draining;

        $this->mailbox->close();
        $this->process->kill(SIGTERM);

        if ($this->readerCid > 0 && Coroutine::exists($this->readerCid)) {
            Coroutine::cancel($this->readerCid);
        }
        if ($this->writerCid > 0 && Coroutine::exists($this->writerCid)) {
            Coroutine::cancel($this->writerCid);
        }

        foreach ($this->pending as $waiter) {
            $waiter->close();
        }
        $this->pending = [];
    }

    private function writerLoop(): void
    {
        while (!$this->stopped) {
            $req = $this->mailbox->pop();
            if ($req === false) {
                return;
            }
            $this->process->write(Codec::encodeRequest($req));
        }
    }

    private function readerLoop(): void
    {
        while (!$this->stopped) {
            $line = $this->process->readLine();
            if ($line === false) {
                $this->state = AgentState::Crashed;
                foreach ($this->pending as $waiter) {
                    $waiter->push(Response::err('', new WorkerCrashedException('worker pipe closed')));
                }
                return;
            }
            try {
                $resp = Codec::decodeResponse($line);
            } catch (\Throwable) {
                continue;
            }
            $waiter = $this->pending[$resp->id] ?? null;
            if ($waiter !== null) {
                $waiter->push($resp);
            }
        }
    }
}
