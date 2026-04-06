<p align="center">
  <img src="brand/logo.svg" alt="Phalanx" width="520">
</p>

# phalanx/ui

Frontend bridge that turns PHP route signatures into TypeScript hooks. Define a handler with typed parameters, and the framework generates an OpenAPI spec that Kubb transforms into React Query mutations, Zod schemas, and type-safe query keys -- no manual API client code, no schema drift.

**This package is in early development.** The foundational primitives (route contracts, input hydration, OpenAPI generation) are implemented in `phalanx/http`. This package will provide the signal system, Kubb configuration, and TypeScript client that complete the PHP-to-browser pipeline.

## Planned Architecture

```
PHP Handler (__invoke signature)
    |
    v
OpenApiGenerator (reflection, no running server)
    |
    v
OpenAPI 3.1 JSON
    |
    v
Kubb (TypeScript types + Zod schemas + React Query hooks)
    |
    v
TanStack Start frontend (SSR, streaming, signals)
```

## What Exists Today (in phalanx/http)

Route contracts, input hydration, and OpenAPI generation are implemented in `phalanx/http` as the foundation this package builds on:

```php
<?php

use Phalanx\ExecutionScope;
use Phalanx\Http\Response\Created;

final readonly class CreateTaskInput
{
    public function __construct(
        public string $title,
        public ?string $description = null,
        public TaskPriority $priority = TaskPriority::Normal,
    ) {}
}

// The __invoke signature IS the API contract
// POST body hydrated automatically, validated, typed
$handler = static function (ExecutionScope $scope, CreateTaskInput $input): Created {
    $task = $scope->service(TaskService::class)->create($input);
    return new Created($task);
};
```

The OpenAPI generator reflects on this signature and produces a complete spec -- request body schema from `CreateTaskInput`, 201 response from `Created`, 422 documented automatically because the handler has typed input.

## What This Package Will Add

**Signal system** -- Backend-driven cache invalidation ported from the [Resonance](https://github.com/jhavenz/resonance) Laravel library. The server tells the frontend what to refetch, where to redirect, and what to flash -- no manual `queryClient.invalidateQueries()` calls. Phalanx extends this beyond request-response: signals flow continuously over WebSocket and SSE connections.

**Kubb configuration** -- Pre-configured Kubb plugin chain (`pluginOas` + `pluginTs` + `pluginZod` + `pluginReactQuery`) with a custom client that processes signals from every response envelope.

**TypeScript client** -- `ResonanceClient` that dispatches signals (invalidate, flash, redirect, event, token) to TanStack Query and TanStack Router. Transport-agnostic: works over HTTP responses, SSE streams, and WebSocket connections.

**Agent streaming hooks** -- `useAgent()` and `useEventStream()` hooks that consume `AgentEvent` streams from `phalanx/ai`, rendering token deltas and tool progress in real time.

## Dependencies

| Package | Purpose |
|---------|---------|
| `phalanx/core` | Scope system, `SelfDescribed` interface |
| `phalanx/http` | Route contracts, `InputHydrator`, `OpenApiGenerator`, response wrappers |
| `phalanx/stream` | `Emitter` pipelines for signal and agent event streaming |
| `phalanx/ws-server` | WebSocket signal transport (optional) |
| `phalanx/ai` | Agent event streaming to frontend (optional) |
