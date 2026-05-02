<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Phalanx\Cancellation\CancellationToken;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final class RequestLifecycle
{
    private ?CancellationToken $token = null;
    private ?Throwable $failure = null;
    private ?string $abortReason = null;

    private function __construct(
        public readonly int $id,
        public readonly ?int $fd,
        public readonly string $method,
        public readonly string $path,
        public private(set) RequestLifecycleState $state = RequestLifecycleState::Opened,
    ) {
    }

    public static function open(int $id, ServerRequestInterface $request, ?int $fd = null): self
    {
        return new self(
            id: $id,
            fd: $fd,
            method: $request->getMethod(),
            path: $request->getUri()->getPath(),
        );
    }

    public function attach(CancellationToken $token): void
    {
        $this->token = $token;
    }

    public function cancel(): void
    {
        $this->token?->cancel();
    }

    public function headersStarted(): void
    {
        if ($this->isTerminal()) {
            return;
        }

        $this->state = RequestLifecycleState::HeadersStarted;
    }

    public function bodyStarted(): void
    {
        if ($this->isTerminal()) {
            return;
        }

        $this->state = RequestLifecycleState::BodyStarted;
    }

    public function complete(): void
    {
        if ($this->isTerminal()) {
            return;
        }

        $this->state = RequestLifecycleState::Completed;
    }

    public function fail(Throwable $failure): void
    {
        if ($this->state === RequestLifecycleState::Aborted) {
            return;
        }

        $this->failure = $failure;
        $this->state = RequestLifecycleState::Failed;
    }

    public function abort(string $reason): void
    {
        if ($this->state === RequestLifecycleState::Completed) {
            return;
        }

        $this->abortReason = $reason;
        $this->state = RequestLifecycleState::Aborted;
        $this->cancel();
    }

    public function failure(): ?Throwable
    {
        return $this->failure;
    }

    public function abortReason(): ?string
    {
        return $this->abortReason;
    }

    public function isTerminal(): bool
    {
        return match ($this->state) {
            RequestLifecycleState::Completed,
            RequestLifecycleState::Failed,
            RequestLifecycleState::Aborted => true,
            RequestLifecycleState::Opened,
            RequestLifecycleState::HeadersStarted,
            RequestLifecycleState::BodyStarted => false,
        };
    }
}
