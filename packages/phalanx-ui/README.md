<p align="center">
  <img src="brand/logo.svg" alt="Phalanx" width="520">
</p>

# phalanx/ui

> **Phalanx** coordinates async PHP through a centralized scope hierarchy, balancing first-class DX with call-site explicitness. Built on the proven work of ReactPHP and AMPHP. Currently stabilizing through active iteration -- [contributions welcome](https://github.com/havy-tech/phalanx-core/blob/main/CONTRIBUTING.md).

Write a PHP handler. Get a TypeScript hook. The `__invoke` signature on your handler class IS the API contract -- the framework reflects on it to generate an OpenAPI spec, and Kubb transforms that spec into typed React Query hooks, Zod schemas, and query keys. One source of truth, full-stack type safety, zero manual API client code.

Phalanx's async nature enables patterns that request-response PHP frameworks can't offer: signals that flow continuously over WebSocket and SSE, concurrent data loading that streams to the page as each fetch resolves, and multi-agent AI output rendered in real time. The server doesn't just respond to requests -- it drives the frontend.

**This package is in early development.** The route contract system, input hydration, and OpenAPI generation are implemented in `phalanx/http`. The signal system, Kubb integration, and TypeScript client are next.

## Table of Contents

- [Quick Start](#quick-start)
- [Route Contracts](#route-contracts)
- [Signals](#signals)
- [Agent Streaming](#agent-streaming)
- [OpenAPI Generation](#openapi-generation)
- [Dependencies](#dependencies)

## Quick Start

Define a handler with typed parameters. The framework does the rest.

```php
<?php

use Phalanx\ExecutionScope;
use Phalanx\Http\Response\Created;
use Phalanx\Http\RouteGroup;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

final readonly class CreateTaskInput
{
    public function __construct(
        public string $title,
        public ?string $description = null,
        public TaskPriority $priority = TaskPriority::Normal,
    ) {}
}

final class CreateTask implements Executable
{
    public function __construct(
        private readonly TaskRepository $tasks,
    ) {}

    public function __invoke(ExecutionScope $scope, CreateTaskInput $input): Created
    {
        return new Created($this->tasks->create($input));
    }
}

final class ListTasks implements Scopeable
{
    public function __construct(
        private readonly TaskRepository $tasks,
    ) {}

    public function __invoke(\Phalanx\Scope $scope, ListTasksQuery $query): TaskCollection
    {
        return $this->tasks->list($query);
    }
}

$routes = RouteGroup::of([
    'POST /tasks' => CreateTask::class,
    'GET /tasks'  => ListTasks::class,
]);
```

The `CreateTaskInput` parameter tells the framework three things at once: hydrate the request body into this DTO, validate it, and document it in the OpenAPI spec. The `Created` return type means 201 status. No attributes, no annotations -- the PHP type system carries the entire contract.

On the frontend, Kubb generates hooks from the spec:

```tsx
import { useCreateTask } from './.generated/hooks';

function NewTaskForm() {
    const create = useCreateTask();

    return (
        <form onSubmit={(e) => {
            e.preventDefault();
            create.mutate({ title: 'Ship phalanx-ui', priority: 'high' });
        }}>
            {/* Fully typed -- TypeScript knows the shape of CreateTaskInput */}
        </form>
    );
}
```

The mutation hook processes signals from the response envelope automatically -- cache invalidation, flash messages, redirects -- without any manual `queryClient.invalidateQueries()` calls.

## Route Contracts

The `__invoke` signature is the single source of truth. Extra typed parameters beyond `ExecutionScope` are auto-hydrated from the request:

- **POST/PUT/PATCH** -- typed parameter hydrated from request body
- **GET/DELETE** -- typed parameter hydrated from query string
- **Return type** -- `Created` (201), `Accepted` (202), `NoContent` (204), or any class (200 with schema)
- **`void` return** -- 204 No Content

DTOs with constructor properties become request/response schemas. Required vs optional, enum values, nullable fields -- all derived from PHP's type system via reflection.

```php
<?php

use Phalanx\Http\Contract\Validatable;

final readonly class CreateTaskInput implements Validatable
{
    public function __construct(
        public string $title,
        public ?string $description = null,
        public TaskPriority $priority = TaskPriority::Normal,
    ) {}

    public function validate(): array
    {
        $errors = [];
        if (strlen($this->title) === 0) {
            $errors['title'][] = 'Title is required';
        }
        return $errors;
    }
}
```

Hydration coerces types (string to int, enum resolution, nullable handling). Validation runs after hydration. Failures produce structured 422 responses before the handler ever executes.

## Signals

The server owns reactivity. Instead of the frontend deciding what to refetch after a mutation, the backend tells it:

```php
<?php

use Phalanx\ExecutionScope;
use Phalanx\Http\Response\Created;
use Phalanx\Task\Executable;
use Phalanx\Ui\Signal;

final class CreateTaskWithSignals implements Executable
{
    public function __construct(
        private readonly TaskRepository $tasks,
    ) {}

    public function __invoke(ExecutionScope $scope, CreateTaskInput $input): Created
    {
        $task = $this->tasks->create($input);

        Signal::invalidate($scope, 'tasks.list');
        Signal::flash($scope, 'Task created', 'success');

        return new Created($task);
    }
}
```

Signals are collected during the request and delivered in the response envelope:

```json
{
    "data": { "id": 1, "title": "Ship phalanx-ui" },
    "meta": {
        "signals": [
            { "type": "invalidate", "scope": ["tasks.list"] },
            { "type": "flash", "message": "Task created", "variant": "success" }
        ]
    }
}
```

The TypeScript client processes these automatically. `invalidate` triggers `queryClient.invalidateQueries()`. `flash` dispatches a toast. `redirect` navigates via TanStack Router. No frontend code required beyond the initial setup.

In Phalanx, signals aren't limited to request-response. They flow continuously over WebSocket and SSE -- a background job completing, a collaborator's edit, an agent finishing a task -- any server-side event can push signals to any connected client.

## Agent Streaming

AI agents produce typed event streams. The frontend renders them in real time:

```php
<?php

use Phalanx\Ai\Agent;
use Phalanx\ExecutionScope;
use Phalanx\Http\RequestScope;
use Phalanx\Http\Sse\SseResponse;
use Phalanx\Task\Executable;

final class AgentChatHandler implements Executable
{
    public function __invoke(ExecutionScope $scope): SseResponse
    {
        /** @var RequestScope $scope */
        $message = $scope->body->required('message');

        $events = $scope->execute(
            Agent::quick('You are a helpful assistant.')->message($message)
        );

        return SseResponse::from(
            $events->filter(static fn($e) => $e->kind->isUserFacing()),
            $scope,
        );
    }
}
```

```tsx
import { useEventStream } from '@phalanx/ui';

function ChatResponse({ prompt }: { prompt: string }) {
    const stream = useEventStream('/chat', { message: prompt });

    return (
        <div>
            {stream.text}
            {stream.toolCalls.map(tool => (
                <ToolProgress key={tool.id} tool={tool} />
            ))}
        </div>
    );
}
```

Multiple agents can stream concurrently to different parts of the same page -- each via its own SSE connection or multiplexed over a single WebSocket. This is where Phalanx's async foundation pays off: 50,000 concurrent SSE connections on a single PHP process, not 100 FPM workers.

## OpenAPI Generation

The spec generator reflects on your route group. No running server required:

```php
<?php

use Phalanx\Http\OpenApi\OpenApiGenerator;

$spec = (new OpenApiGenerator(title: 'Task API', version: '1.0.0'))
    ->generate($routes);

file_put_contents('openapi.json', json_encode($spec, JSON_PRETTY_PRINT));
```

Point Kubb at the spec. It generates TypeScript types, Zod validation schemas, and React Query hooks. The custom Kubb client processes signals from every response envelope -- no per-hook signal handling required.

Handlers that implement `SelfDescribed` and `Tagged` contribute summaries and tags to the spec. Handlers that don't still generate complete path/schema documentation from their type signatures alone.

## Dependencies

| Package | Purpose |
|---------|---------|
| `phalanx/core` | Scope system, `SelfDescribed` interface |
| `phalanx/http` | Route contracts, input hydration, OpenAPI generation, response wrappers |
| `phalanx/stream` | Emitter pipelines for signal and agent event streaming |
| `phalanx/ws-server` | WebSocket signal transport (optional) |
| `phalanx/ai` | Agent event streaming to frontend (optional) |
