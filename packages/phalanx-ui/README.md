<?php # phalanx/ui — Frontend Bridge

Typed route contracts, OpenAPI generation, and signal-based reactivity for Phalanx applications. Connects async PHP backends to TanStack Start + React frontends via Kubb-generated TypeScript hooks.

## Installation

```bash
composer require phalanx/ui
```

## What This Package Provides

**Route contracts** — the `__invoke` signature on handler classes IS the API contract. Extra typed parameters beyond the scope are auto-hydrated from request body (POST/PUT/PATCH) or query string (GET/DELETE). No attributes, no annotations.

**Input hydration + validation** — DTOs with typed constructor properties are hydrated automatically. Type coercion (string → int, enum resolution, nullable handling) and business rule validation via the `Validatable` interface.

**Response wrappers** — `Created`, `Accepted`, `NoContent` encode HTTP status codes in the return type. The spec generator reads them; the runner serializes them.

**OpenAPI generation** — `OpenApiGenerator` reflects on a `RouteGroup` to produce a complete OpenAPI 3.1 spec. No running server needed. Designed for Kubb consumption.

**Signal system** — *(coming soon)* Backend-driven cache invalidation, flash messages, redirects, and custom events pushed to the frontend via response envelope or persistent WebSocket/SSE connections.

## Quick Start

```php
<?php

use Phalanx\Http\Response\Created;
use Phalanx\Http\Route;
use Phalanx\Http\RouteGroup;
use Phalanx\Http\OpenApi\OpenApiGenerator;

// Define routes with typed contracts
$routes = RouteGroup::of([
    'POST /tasks' => Route::of(static function (\Phalanx\ExecutionScope $scope, CreateTaskInput $input): Created {
        $task = $scope->service(TaskService::class)->create($input);
        return new Created($task);
    }),
    'GET /tasks' => Route::of(static function (\Phalanx\ExecutionScope $scope, ListTasksQuery $query): TaskCollection {
        return $scope->service(TaskService::class)->list($query);
    }),
]);

// Generate OpenAPI spec for Kubb
$spec = (new OpenApiGenerator(title: 'My API', version: '1.0.0'))->generate($routes);
file_put_contents('openapi.json', json_encode($spec, JSON_PRETTY_PRINT));
```

## Status

This package is in active development. The route contract and OpenAPI generation systems are implemented. The signal system (Resonance port) and TypeScript client are next.

## Requirements

- PHP 8.4+
- phalanx/core ^0.5
- phalanx/http ^0.5
- phalanx/stream ^0.5
