<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Runtime\Memory\ManagedResource;
use Phalanx\Runtime\Memory\ManagedResourceHandle;
use Phalanx\Runtime\Memory\ManagedResourceState;
use Phalanx\Runtime\RuntimeContext;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final class StoaRequestResource
{
    public const string TYPE = 'stoa.http_request';

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
        $handle = $runtime->memory->resources->open(
            type: self::TYPE,
            id: $runtime->memory->ids->nextRuntime('stoa-request'),
            parentResourceId: $ownerScopeId,
            ownerScopeId: $ownerScopeId,
        );

        $resource = new self(
            runtime: $runtime,
            token: $token,
            handle: $handle,
            fd: $fd,
            id: $handle->id,
            path: $request->getUri()->getPath(),
            method: $request->getMethod(),
        );

        $resource->annotate('stoa.method', $resource->method);
        $resource->annotate('stoa.path', $resource->path);
        if ($fd !== null) {
            $resource->annotate('stoa.fd', $fd);
        }

        return $resource;
    }

    public function activate(): void
    {
        $this->handle = $this->runtime->memory->resources->activate($this->handle);
    }

    public function routeMatched(string $route): void
    {
        $this->annotate('stoa.route', $route);
        $this->event('stoa.route_matched', $route);
    }

    public function responseStatus(int $status): void
    {
        $this->annotate('stoa.status', $status);
    }

    public function headersStarted(): void
    {
        $this->event('stoa.response.headers_started');
    }

    public function bodyStarted(): void
    {
        $this->event('stoa.response.body_started');
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
        $this->event('stoa.request_failed', $failure::class);

        if ($this->state()?->isTerminal() === true) {
            return;
        }

        $this->runtime->memory->resources->fail($this->id, $failure::class);
    }

    public function abort(string $reason): void
    {
        $this->event('stoa.request_aborted', $reason);

        if ($this->state()?->isTerminal() !== true) {
            $this->runtime->memory->resources->abort($this->id, $reason);
        }

        $this->token->cancel();
    }

    public function release(): void
    {
        if ($this->snapshot() === null) {
            return;
        }

        $this->runtime->memory->resources->release($this->id);
    }

    public function event(string $type, string $valueA = '', string $valueB = ''): void
    {
        $this->runtime->memory->resources->recordEvent($this->id, $type, $valueA, $valueB);
    }

    public function annotate(string $key, string|int|float|bool|null $value): void
    {
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

    private function isTerminal(): bool
    {
        return $this->state()?->isTerminal() === true;
    }
}
