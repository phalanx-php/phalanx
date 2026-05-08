<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Runtime\Memory\ManagedResource;
use Phalanx\Runtime\Memory\ManagedResourceHandle;
use Phalanx\Runtime\Memory\ManagedResourceState;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Stoa\Response\ResponseLeaseDomain;
use Phalanx\Stoa\Runtime\Identity\StoaAnnotationSid;
use Phalanx\Stoa\Runtime\Identity\StoaEventSid;
use Phalanx\Stoa\Runtime\Identity\StoaResourceSid;
use Phalanx\Stoa\Runtime\StoaScopeKey;
use Phalanx\Supervisor\DeliveryLease;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final class StoaRequestResource
{
    private const int ANNOTATION_LIMIT = 240;
    private const int EVENT_LIMIT = 120;

    private bool $deliveryLeaseHeld = false;
    private int $deliveryLeaseFd = 0;

    private function __construct(
        private readonly RuntimeContext $runtime,
        private readonly CancellationToken $token,
        private ManagedResourceHandle $handle,
        public readonly ?int $fd,
        public readonly string $id,
        public readonly string $path,
        public readonly string $method,
    ) {
    }

    public static function open(
        RuntimeContext $runtime,
        ServerRequestInterface $request,
        CancellationToken $token,
        ?int $fd = null,
        ?string $ownerScopeId = null,
    ): self {
        $resource = null;
        $handle = $runtime->memory->resources->open(
            type: StoaResourceSid::HttpRequest,
            id: $runtime->memory->ids->nextRuntime('stoa-request'),
            parentResourceId: $ownerScopeId,
            ownerScopeId: $ownerScopeId,
        );

        try {
            $resource = new self(
                runtime: $runtime,
                token: $token,
                handle: $handle,
                fd: $fd,
                id: $handle->id,
                path: $request->getUri()->getPath(),
                method: $request->getMethod(),
            );

            $resource->annotate(StoaAnnotationSid::Method, $resource->method);
            $resource->annotate(StoaAnnotationSid::Path, $resource->path);
            if ($fd !== null) {
                $resource->annotate(StoaAnnotationSid::Fd, $fd);
            }
        } catch (Throwable $e) {
            if ($resource !== null) {
                $resource->release();
            } else {
                $runtime->memory->resources->release($handle->id);
            }

            throw $e;
        }

        return $resource;
    }

    public static function fromScope(ExecutionScope $scope): ?self
    {
        $resource = $scope->attribute(StoaScopeKey::RequestResource->value);

        return $resource instanceof self ? $resource : null;
    }

    /**
     * Cancellation token bound to this HTTP request lifecycle. Cancels when
     * Stoa aborts the request (e.g., {@see StoaRunner::handleClose} on fd
     * disconnect). Exposed so {@see HttpUpgradeable} implementations can chain
     * downstream session tokens to abrupt-disconnect propagation.
     */
    public function cancellation(): CancellationToken
    {
        return $this->token;
    }

    public function activate(): void
    {
        $this->handle = $this->runtime->memory->resources->activate($this->handle);
    }

    public function routeMatched(string $route): void
    {
        $this->annotate(StoaAnnotationSid::Route, $route);
        $this->event(StoaEventSid::RouteMatched, $route);
    }

    public function responseStatus(int $status): void
    {
        $this->annotate(StoaAnnotationSid::Status, $status);
    }

    public function headersStarted(): void
    {
        $this->event(StoaEventSid::ResponseHeadersStarted);
    }

    public function bodyStarted(): void
    {
        $this->event(StoaEventSid::ResponseBodyStarted);
    }

    public function complete(int $status): void
    {
        $this->responseStatus($status);

        if ($this->isTerminal()) {
            return;
        }

        $this->runtime->memory->resources->close($this->id, "status:{$status}");
    }

    public function fail(Throwable $failure): void
    {
        if ($this->snapshot() === null || $this->isTerminal()) {
            return;
        }

        $reason = self::fit($failure::class, self::EVENT_LIMIT);
        $this->recordDiagnostic(StoaEventSid::RequestFailed, $reason);

        if ($this->snapshot() !== null && !$this->isTerminal()) {
            $this->runtime->memory->resources->fail($this->id, $reason);
        }
    }

    public function abort(string $reason): void
    {
        try {
            if ($this->snapshot() !== null && !$this->isTerminal()) {
                $reason = self::fit($reason, self::EVENT_LIMIT);
                $this->recordDiagnostic(StoaEventSid::RequestAborted, $reason);
                $this->runtime->memory->resources->abort($this->id, $reason);
            }
        } finally {
            $this->token->cancel();
        }
    }

    public function release(): void
    {
        if ($this->deliveryLeaseHeld) {
            $this->releaseDeliveryLease('released');
        }

        if ($this->snapshot() === null) {
            return;
        }

        $this->runtime->memory->resources->release($this->id);
    }

    public function acquireDeliveryLease(int $fd): void
    {
        if ($this->deliveryLeaseHeld) {
            return;
        }

        $this->runtime->memory->resources->addLease($this->id, $this->id, [
            'lease_type' => DeliveryLease::class,
            'domain' => ResponseLeaseDomain::DOMAIN,
            'resource_key' => (string) $fd,
            'mode' => 'flush',
            'acquired_at' => microtime(true),
        ]);

        $this->deliveryLeaseHeld = true;
        $this->deliveryLeaseFd = $fd;
        $this->event(StoaEventSid::ResponseLeaseAcquired, (string) $fd);
    }

    public function releaseDeliveryLease(string $reason = 'fulfilled'): void
    {
        if (!$this->deliveryLeaseHeld) {
            return;
        }

        $this->runtime->memory->resources->releaseLease(
            ownerResourceId: $this->id,
            leaseType: DeliveryLease::class,
            domain: ResponseLeaseDomain::DOMAIN,
            resourceKey: (string) $this->deliveryLeaseFd,
        );

        $event = $reason === 'fulfilled'
            ? StoaEventSid::ResponseLeaseFulfilled
            : StoaEventSid::ResponseLeaseAbandoned;
        $this->event($event, (string) $this->deliveryLeaseFd, $reason);

        $this->deliveryLeaseHeld = false;
    }

    public function event(StoaEventSid $type, string $valueA = '', string $valueB = ''): void
    {
        $this->runtime->memory->resources->recordEvent(
            $this->id,
            $type,
            self::fit($valueA, self::EVENT_LIMIT),
            self::fit($valueB, self::EVENT_LIMIT),
        );
    }

    public function annotate(StoaAnnotationSid $key, string|int|float|bool|null $value): void
    {
        if (is_string($value)) {
            $value = self::fit($value, self::ANNOTATION_LIMIT);
        }

        $this->runtime->memory->resources->annotate($this->id, $key, $value);
    }

    public function snapshot(): ?ManagedResource
    {
        return $this->runtime->memory->resources->get($this->id);
    }

    public function state(): ?ManagedResourceState
    {
        return $this->snapshot()?->state;
    }

    public function stateValue(): string
    {
        $snapshot = $this->snapshot();

        return $snapshot === null ? 'released' : $snapshot->state->value;
    }

    public function isTerminal(): bool
    {
        return $this->state()?->isTerminal() === true;
    }

    private static function fit(string $value, int $limit): string
    {
        return mb_strlen($value) <= $limit ? $value : mb_substr($value, 0, $limit);
    }

    private function recordDiagnostic(StoaEventSid $type, string $valueA = '', string $valueB = ''): void
    {
        try {
            $this->event($type, $valueA, $valueB);
        } catch (Cancelled $e) {
            throw $e;
        } catch (Throwable) {
        }
    }
}
