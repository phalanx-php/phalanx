<?php

declare(strict_types=1);

namespace Phalanx\Worker;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Worker\Protocol\Response;
use Phalanx\Worker\Protocol\TaskRequest;
use RuntimeException;
use Throwable;

class ParallelWorkerDispatch implements WorkerDispatch
{
    private static int $idSeq = 0;

    public function __construct(public readonly WorkerSupervisor $supervisor)
    {
    }

    public function dispatch(Scopeable|Executable $task, CancellationToken $token): mixed
    {
        $token->throwIfCancelled();

        $worker = $this->supervisor->pick();
        $id = (string) ++self::$idSeq;
        $request = new TaskRequest($id, serialize($task));

        // If the parent scope cancels while the worker is mid-flight, kill the
        // child process. The reader loop sees the closed pipe, surfaces a
        // Cancelled response on the waiter, and submit() returns. The worker
        // is marked Crashed; subsequent dispatches go to a sibling.
        $unregister = $token->onCancel(static function () use ($worker, $id): void {
            $worker->abortInFlight($id);
        });

        try {
            $resp = $worker->submit($request);
        } finally {
            $unregister();
        }

        if ($resp->kind === Response::KIND_OK) {
            return unserialize($resp->serializedValue);
        }
        $errData = unserialize($resp->serializedValue);
        $message = is_array($errData) ? (string) ($errData['message'] ?? 'worker error') : 'worker error';
        $class = is_array($errData) ? (string) ($errData['class'] ?? RuntimeException::class) : RuntimeException::class;
        if (is_a($class, Throwable::class, true)) {
            /** @var Throwable $exception */
            $exception = new $class($message);
            throw $exception;
        }
        throw new RuntimeException($message);
    }

    public function shutdown(): void
    {
        $this->supervisor->shutdown();
    }
}
