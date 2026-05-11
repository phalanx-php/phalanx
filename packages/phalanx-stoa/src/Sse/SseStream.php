<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Sse;

use OpenSwoole\Http\Response;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\Suspendable;
use Phalanx\Stoa\Runtime\Identity\StoaEventSid;
use Phalanx\Stoa\StoaRequestResource;
use Phalanx\Supervisor\WaitReason;
use Throwable;

/**
 * Live SSE stream bound to a single OpenSwoole client fd.
 *
 * Writes go through {@see Suspendable::call()} so cancellation, supervisor
 * wait-reason classification, and diagnostics surface naturally. Each
 * `writeEvent()` corresponds to one SSE frame; the underlying TCP send
 * may be backpressured by the kernel buffer, in which case BufferEmpty
 * lifecycle observers (Stoa\Response\BufferEventDispatcher) release the
 * delivery lease once the buffer drains.
 *
 * Closure policy: every write closure is `static` and pulls the response
 * target via parameter, never via captured `$this`.
 */
final class SseStream
{
    private bool $closed = false;

    public function __construct(
        private readonly Suspendable $scope,
        private readonly Response $response,
        private readonly StoaRequestResource $request,
        private readonly CancellationToken $cancellation,
    ) {
    }

    public function writeEvent(
        string $data,
        ?string $event = null,
        ?string $id = null,
        ?int $retryMs = null,
    ): void {
        $this->writeRaw(SseEncoder::event($data, $event, $id, $retryMs));
    }

    public function writeComment(string $comment): void
    {
        $this->writeRaw(SseEncoder::comment($comment));
    }

    public function writeRaw(string $payload): void
    {
        if ($this->closed) {
            return;
        }

        $this->cancellation->throwIfCancelled();

        $response = $this->response;
        $bytes = strlen($payload);

        try {
            $written = $this->scope->call(
                static fn(): bool => $response->write($payload),
                WaitReason::streamWrite('stoa.sse', $bytes),
            );
        } catch (Cancelled $e) {
            $this->markAbandoned('cancelled');
            throw $e;
        } catch (Throwable $e) {
            $this->markAbandoned('write_error:' . $e::class);
            throw $e;
        }

        if ($written === false) {
            $this->markAbandoned('write_returned_false');
            throw new SseWriteFailure('SSE chunk write failed.');
        }
    }

    public function close(string $reason = 'closed'): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        try {
            if ($this->response->isWritable()) {
                $this->response->end();
            }
        } catch (Cancelled $c) {
            throw $c;
        } catch (Throwable) {
            // best-effort close; the request will mark itself failed elsewhere.
        }

        $this->request->event(StoaEventSid::SseStreamClosed, $reason);

        if ($reason === 'closed') {
            $this->request->releaseDeliveryLease('fulfilled');
        } else {
            $this->request->releaseDeliveryLease('abandoned:' . $reason);
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    private function markAbandoned(string $reason): void
    {
        $this->closed = true;
        $this->request->event(StoaEventSid::SseStreamClosed, $reason);
        $this->request->releaseDeliveryLease('abandoned:' . $reason);
    }
}
