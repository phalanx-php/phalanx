<p align="center">
  <img src="brand/logo.svg" alt="Phalanx" width="520">
</p>

# phalanx/http

> **Phalanx** is a first-principles rethinking of what PHP can be when modern language features and a decade of async community work are treated as the foundation, not an afterthought. [Read more](https://github.com/havy-tech/phalanx-core#phalanx-core---async-php) in the core library.

Async HTTP server built on ReactPHP with scope-driven request handling. Every route handler receives an `ExecutionScope` with full access to concurrent task execution, service injection, and cancellation -- write concurrent data-fetching code that reads like sequential PHP.

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Defining Routes](#defining-routes)
- [Route Groups](#route-groups)
- [Route Parameters](#route-parameters)
- [Route Contracts](#route-contracts)
- [Input Validation](#input-validation)
- [Concurrent Request Handling](#concurrent-request-handling)
- [Middleware](#middleware)
- [Mounting Sub-Groups](#mounting-sub-groups)
- [Loading Routes from Files](#loading-routes-from-files)
- [Server-Sent Events](#server-sent-events)
- [UDP Listeners](#udp-listeners)
- [Authentication](#authentication)
- [WebSocket Integration](#websocket-integration)
- [ToResponse Interface](#toresponse-interface)
- [Response Wrappers](#response-wrappers)
- [Request Validators](#request-validators)
- [OpenAPI Generation](#openapi-generation)

## Installation

```bash
composer require phalanx/http
```

Requires PHP 8.4+, `phalanx/core`, `react/http`, and `nikic/fast-route`.

## Quick Start

```php
<?php

use Phalanx\Application;
use Phalanx\Http\RouteGroup;
use Phalanx\Http\Runner;
use Phalanx\Scope;
use Phalanx\Task\Scopeable;
use React\Http\Message\Response;

final class HealthCheck implements Scopeable
{
    public function __invoke(Scope $scope): mixed
    {
        return Response::plaintext('Hello, Phalanx!');
    }
}

$app = Application::starting()->compile();

$routes = RouteGroup::of([
    'GET /hello' => HealthCheck::class,
]);

Runner::from($app)
    ->withRoutes($routes)
    ->run('0.0.0.0:8080');
```

```
$ curl http://localhost:8080/hello
Hello, Phalanx!
```

For anything beyond a one-liner, use an invokable class implementing `Scopeable` or `Executable` instead of an inline closure. Named handlers are traceable, testable, and carry their own identity through the system.

## Defining Routes

Routes are class-strings registered in a `RouteGroup`. The `HandlerResolver` constructs the handler at dispatch time with dependencies injected from the service container, then calls `__invoke` with a `RequestScope` (which extends `ExecutionScope`). Constructor injection makes dependencies explicit at the class level and keeps `__invoke` bodies focused on the work:

```php
<?php

use Phalanx\Http\RequestScope;
use Phalanx\Scope;
use Phalanx\Task\Scopeable;
use React\Http\Message\Response;

final class ShowUser implements Scopeable
{
    public function __construct(
        private readonly UserRepository $users,
    ) {}

    public function __invoke(Scope $scope): mixed
    {
        /** @var RequestScope $scope */
        $user = $this->users->find($scope->params->get('id'));

        return Response::json($user);
    }
}
```

```php
<?php

use Phalanx\Http\RouteGroup;

$routes = RouteGroup::of([
    'GET /users/{id}' => ShowUser::class,
]);
```

Route keys use the `METHOD /path` format. Multiple methods can be comma-separated:

```php
<?php

$routes = RouteGroup::of([
    'GET /users'       => ListUsers::class,
    'POST /users'      => CreateUser::class,
    'GET,HEAD /health' => HealthCheck::class,
]);
```

## Route Groups

`RouteGroup` collects routes into a dispatch table backed by FastRoute. Build from an array using `"METHOD /path" => HandlerClass::class` keys:

```php
<?php

use Phalanx\Http\RouteGroup;

$routes = RouteGroup::of([
    'GET /users'      => ListUsers::class,
    'POST /users'     => CreateUser::class,
    'GET /users/{id}' => ShowUser::class,
]);

// Merge groups
$all = $apiRoutes->merge($adminRoutes);
```

## Route Parameters

Path parameters use `{name}` syntax. Named pattern aliases constrain parameters to specific formats. The default set includes `int`, `uuid`, `slug`, `year`, `month`, `day`, `date`, and `any`. Add your own with `withPatterns()`:

```php
<?php

use Phalanx\Http\RouteGroup;

$routes = RouteGroup::of([
    'GET /users/{id:int}'             => ShowUser::class,
    'GET /posts/{slug:slug}'          => ShowPost::class,
    'GET /orgs/{orgId:uuid}/projects' => ListProjects::class,
])->withPatterns([
    'int'  => '\d+',
    'uuid' => '[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}',
    'slug' => '[a-z0-9-]+',
]);
```

The built-in patterns are pre-registered -- the `withPatterns()` call above is only needed when adding custom ones. Access parameters through `$scope->params`:

```php
<?php

use Phalanx\Http\RequestScope;
use Phalanx\Scope;
use Phalanx\Task\Scopeable;
use React\Http\Message\Response;

final class ShowUser implements Scopeable
{
    public function __construct(
        private readonly UserRepository $users,
    ) {}

    public function __invoke(Scope $scope): mixed
    {
        /** @var RequestScope $scope */
        $id = $scope->params->get('id');
        $user = $this->users->find($id);

        return Response::json($user);
    }
}
```

The `RequestScope` exposes `$request`, `$params`, `$query`, `$body`, and `$config` through typed property hooks. Convenience methods -- `$scope->method()`, `$scope->path()`, `$scope->header()`, `$scope->isJson()`, `$scope->bearerToken()` -- wrap common PSR-7 access patterns.

## Route Contracts

The `__invoke` signature of a handler is the complete contract for a route. Beyond the scope parameter, any additional typed class parameter is automatically hydrated from request data before dispatch -- no manual parsing, no `$scope->body->get(...)` boilerplate.

`InputHydrator` reflects on the handler's `__invoke` at first dispatch and caches the result. It skips parameters typed as scope interfaces (`Scope`, `ExecutionScope`, `RequestScope`) and targets the first remaining class-typed parameter. The source for hydration follows HTTP convention:

- `POST`, `PUT`, `PATCH` -- hydrated from the request body
- `GET`, `DELETE`, and all other methods -- hydrated from query string parameters

Handlers with no extra typed parameter work exactly as before -- the scope is passed as the sole argument.

### POST handler with a typed input DTO

```php
<?php

use Phalanx\ExecutionScope;
use Phalanx\Http\RequestScope;
use Phalanx\Http\Response\Created;
use Phalanx\Task\Executable;

final readonly class CreateTaskInput
{
    public function __construct(
        public string $title,
        public ?string $description = null,
        public int $priority = 0,
    ) {}
}

final class CreateTask implements Executable
{
    public function __construct(
        private readonly TaskRepository $tasks,
    ) {}

    public function __invoke(ExecutionScope $scope, CreateTaskInput $input): Created
    {
        /** @var RequestScope $scope */
        $task = $this->tasks->create(
            title: $input->title,
            description: $input->description,
            priority: $input->priority,
        );

        return new Created($task);
    }
}
```

```php
<?php

use Phalanx\Http\RouteGroup;

$routes = RouteGroup::of([
    'POST /tasks' => CreateTask::class,
]);
```

The JSON body keys map to constructor parameter names. Missing required fields produce a 422 before the handler is called.

### GET handler with a typed query DTO

```php
<?php

use Phalanx\Http\RequestScope;
use Phalanx\Scope;
use Phalanx\Task\Scopeable;
use React\Http\Message\Response;

final readonly class ListTasksQuery
{
    public function __construct(
        public int $page = 1,
        public int $perPage = 25,
        public ?string $status = null,
    ) {}
}

final class ListTasks implements Scopeable
{
    public function __construct(
        private readonly TaskRepository $tasks,
    ) {}

    public function __invoke(Scope $scope, ListTasksQuery $query): mixed
    {
        /** @var RequestScope $scope */
        $result = $this->tasks->paginate(
            page: $query->page,
            perPage: $query->perPage,
            status: $query->status,
        );

        return Response::json($result);
    }
}
```

Query string values are coerced to constructor parameter types -- `?page=2&perPage=50` arrives as `int $page = 2`, `int $perPage = 50`.

### Handler with no typed input

```php
<?php

use Phalanx\Http\RequestScope;
use Phalanx\Scope;
use Phalanx\Task\Scopeable;
use React\Http\Message\Response;

final class HealthCheck implements Scopeable
{
    public function __invoke(Scope $scope): mixed
    {
        return Response::json(['status' => 'ok']);
    }
}
```

No extra parameter -- the scope is the only argument. Hydration is a no-op.

## Input Validation

### Type coercion

`InputHydrator` coerces raw string data to the declared constructor parameter type:

| Target type | Coercion |
|---|---|
| `string` | `(string) $value` |
| `int` | `(int) $value` (fails if non-numeric) |
| `float` | `(float) $value` (fails if non-numeric) |
| `bool` | `filter_var($value, FILTER_VALIDATE_BOOLEAN)` |
| `array` | passed through if already an array, error otherwise |
| backed enum | `EnumClass::from($value)` with allowed-values error on failure |

Coercion errors are collected across all fields before throwing. The handler never executes when coercion fails.

### The `Validatable` interface

After successful construction, if the DTO implements `Validatable`, its `validate()` method is called. Return an empty array to pass, or a `field => messages` map to fail:

```php
<?php

use Phalanx\Http\Contract\Validatable;

final readonly class CreateTaskInput implements Validatable
{
    public function __construct(
        public string $title,
        public int $priority = 0,
    ) {}

    public function validate(): array
    {
        $errors = [];

        if (strlen($this->title) < 3) {
            $errors['title'][] = 'Must be at least 3 characters';
        }

        if ($this->priority < 0 || $this->priority > 10) {
            $errors['priority'][] = 'Must be between 0 and 10';
        }

        return $errors;
    }
}
```

`validate()` runs after construction, so it has access to the fully typed, coerced values. It is the right place for cross-field rules or domain constraints that go beyond type correctness.

### 422 responses

Both coercion failures and `Validatable` failures throw `ValidationException`. The runner catches this and returns a 422 with a JSON body:

```json
{
  "error": "Validation failed (2 error(s))",
  "errors": {
    "title": ["Must be at least 3 characters"],
    "priority": ["Must be between 0 and 10"]
  }
}
```

`ValidationException` can also be thrown manually from handler code when domain validation fails after the DTO is already hydrated:

```php
<?php

use Phalanx\Http\ValidationException;

throw ValidationException::single('email', 'Already in use');
throw ValidationException::fromErrors(['email' => ['Already in use'], 'name' => ['Taken']]);
```

## Concurrent Request Handling

Every route handler has access to Phalanx's concurrency primitives through the scope. Fetch data from multiple sources concurrently within a single request:

```php
<?php

use Phalanx\ExecutionScope;
use Phalanx\Http\RequestScope;
use Phalanx\Task;
use Phalanx\Task\Executable;
use React\Http\Message\Response;

final class DashboardHandler implements Executable
{
    public function __construct(
        private readonly PgPool $db,
        private readonly RedisClient $redis,
    ) {}

    public function __invoke(ExecutionScope $scope): mixed
    {
        /** @var RequestScope $scope */
        $db = $this->db;
        $redis = $this->redis;

        [$stats, $alerts, $recent] = $scope->concurrent([
            Task::of(static fn() => $db->query(
                'SELECT count(*) as total FROM orders WHERE date = CURRENT_DATE'
            )),
            Task::of(static fn() => $redis->get('alerts:active')),
            Task::of(static fn() => $db->query(
                'SELECT * FROM activity ORDER BY created_at DESC LIMIT 10'
            )),
        ]);

        return Response::json(compact('stats', 'alerts', 'recent'));
    }
}
```

```php
<?php

use Phalanx\Http\RouteGroup;

$routes = RouteGroup::of([
    'GET /dashboard' => DashboardHandler::class,
]);
```

Three I/O operations, one request, wall-clock time of the slowest. The handler reads like synchronous code -- no promises, no callbacks, no `yield`.

## Middleware

Wrap an entire route group with middleware. `wrap()` accepts class-strings -- the framework resolves middleware from the container at dispatch time:

```php
<?php

use Phalanx\Http\RouteGroup;

$api = RouteGroup::of([
    'GET /me'       => GetProfile::class,
    'PUT /me'       => UpdateProfile::class,
    'GET /settings' => GetSettings::class,
])->wrap(AuthMiddleware::class, CorsMiddleware::class);
```

Composition order is group (outermost) -> per-route config -> handler (innermost). Class-string deduplication applies -- innermost wins when the same middleware appears at multiple levels.

## Mounting Sub-Groups

Nest route groups under a path prefix with `mount()`:

```php
<?php

use Phalanx\Http\RouteGroup;

$v1 = RouteGroup::of([
    'GET /users'  => ListUsers::class,
    'POST /users' => CreateUser::class,
]);

$v2 = RouteGroup::of([
    'GET /users'  => ListUsersV2::class,
    'POST /users' => CreateUserV2::class,
]);

$api = RouteGroup::of([])
    ->mount('/api/v1', $v1)
    ->mount('/api/v2', $v2);
```

Requests to `/api/v1/users` and `/api/v2/users` dispatch to their respective handlers.

## Loading Routes from Files

`RouteLoader` scans a directory of PHP files that each return a `RouteGroup`:

```php
<?php

use Phalanx\Http\RouteLoader;

$routes = RouteLoader::loadDirectory(__DIR__ . '/routes');
```

Each file defines its routes with class-string handlers:

```php
<?php

// routes/users.php
use Phalanx\Http\RouteGroup;

return RouteGroup::of([
    'GET /users'        => ListUsers::class,
    'GET /users/{id}'   => ShowUser::class,
    'POST /users'       => CreateUser::class,
    'DELETE /users/{id}' => DeleteUser::class,
]);
```

`Runner` accepts directory paths directly:

```php
<?php

use Phalanx\Http\Runner;

Runner::from($app)
    ->withRoutes(__DIR__ . '/routes')
    ->run();
```

## Server-Sent Events

Push real-time updates to clients with `SseResponse` and `SseChannel`.

### Single-stream SSE

`SseResponse` converts an `Emitter` into a streaming HTTP response:

```php
<?php

use Phalanx\ExecutionScope;
use Phalanx\Http\RequestScope;
use Phalanx\Http\Sse\SseResponse;
use Phalanx\Stream\Emitter;
use Phalanx\Task\Executable;

final class MetricsStream implements Executable
{
    public function __construct(
        private readonly MetricsCollector $metrics,
    ) {}

    public function __invoke(ExecutionScope $scope): mixed
    {
        /** @var RequestScope $scope */
        $metrics = $this->metrics;

        $source = Emitter::produce(static function ($ch, $ctx) use ($scope, $metrics) {
            while (!$ctx->isCancelled()) {
                $ch->emit(json_encode($metrics->snapshot()));
                $scope->delay(1.0);
            }
        });

        return SseResponse::from($source, $scope, event: 'metrics');
    }
}
```

```php
<?php

use Phalanx\Http\RouteGroup;

$routes = RouteGroup::of([
    'GET /events/metrics' => MetricsStream::class,
]);
```

### Broadcast SSE with SseChannel

`SseChannel` manages multiple connected clients with automatic replay on reconnect:

```php
<?php

use Phalanx\Http\Sse\SseChannel;

// Register the channel as a service
$channel = new SseChannel(bufferSize: 200, defaultEvent: 'update');

// Connect clients (in a route handler)
$channel->connect($responseStream, lastEventId: $request->getHeaderLine('Last-Event-ID') ?: null);

// Publish from anywhere with access to the channel
$channel->send(json_encode($payload), event: 'price-change');
```

Missed events replay automatically when a client reconnects with `Last-Event-ID`.

## UDP Listeners

The runner supports UDP alongside HTTP on the same event loop:

```php
<?php

use Phalanx\Http\Runner;

Runner::from($app)
    ->withRoutes($routes)
    ->withUdp(
        handler: static function (string $data, string $remote, $scope): void {
            $scope->service(MetricsIngester::class)->ingest($data, $remote);
        },
        port: 8081,
    )
    ->run();
```

HTTP on 8080, UDP on 8081, single process.

## Authentication

Protect routes with the built-in `Authenticate` middleware. Implement a `Guard` to resolve identity from the request, and an `Identity` for your user model:

```php
<?php

use Phalanx\Auth\AuthContext;
use Phalanx\Auth\Guard;
use Psr\Http\Message\ServerRequestInterface;

final class JwtGuard implements Guard
{
    public function __construct(private readonly string $secret) {}

    public function resolve(ServerRequestInterface $request): ?AuthContext
    {
        $token = $this->extractBearer($request);
        $claims = $this->verifyJwt($token, $this->secret);

        if ($claims === null) {
            return null;
        }

        return AuthContext::authenticated(
            new AppUser($claims['sub']),
            $token,
            $claims['abilities'] ?? [],
        );
    }
}
```

Register `JwtGuard` as a service and apply `Authenticate` to a route group:

```php
<?php

use Phalanx\Http\Auth\Authenticate;
use Phalanx\Http\RouteGroup;

$api = RouteGroup::of([
    'GET /me'  => GetProfile::class,
    'PUT /me'  => UpdateProfile::class,
])->wrap(Authenticate::class);
```

Inside handlers, the auth context is available as an attribute:

```php
<?php

$auth = $scope->attribute('auth');
$userId = $auth->identity->id;

if ($auth->can('admin')) {
    // ...
}
```

For typed access, use `AuthenticatedRequestScope` which adds `$scope->auth`:

```php
<?php

use Phalanx\Http\AuthenticatedRequestScope;

/** @var AuthenticatedRequestScope $scope */
$scope->auth->identity->id;
$scope->auth->can('write');
$scope->auth->token();
```

## ToResponse Interface

Domain objects can implement `ToResponse` to control their own HTTP serialization. The `Runner` calls `toResponse()` automatically when a handler returns a `ToResponse` instance.

The interface requires a `$status` property hook alongside the `toResponse()` method -- both are needed for the runner and OpenAPI generator to work correctly:

```php
<?php

use Phalanx\Http\ToResponse;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;

final readonly class ApiResult implements ToResponse
{
    public int $status {
        get => 200;
    }

    public function __construct(
        private array $data,
    ) {}

    public function toResponse(): ResponseInterface
    {
        return Response::json($this->data)->withStatus($this->status);
    }
}
```

Domain exception classes can also implement `ToResponse`. The runner catches these during dispatch and converts them to HTTP responses automatically -- no try/catch boilerplate in handler code.

## Response Wrappers

Three concrete `ToResponse` implementations cover the most common non-200 success statuses:

| Class | Status | Body |
|---|---|---|
| `Created` | 201 | JSON-encoded `$data` |
| `Accepted` | 202 | JSON-encoded `$data` |
| `NoContent` | 204 | empty |

All three are in the `Phalanx\Http\Response` namespace and implement `ToResponse`, so they flow through the same dispatch path as any custom `ToResponse` object.

```php
<?php

use Phalanx\ExecutionScope;
use Phalanx\Http\RequestScope;
use Phalanx\Http\Response\Created;
use Phalanx\Http\Response\NoContent;
use Phalanx\Task\Executable;

final class CreateUser implements Executable
{
    public function __construct(
        private readonly UserRepository $users,
    ) {}

    public function __invoke(ExecutionScope $scope, CreateUserInput $input): Created
    {
        /** @var RequestScope $scope */
        return new Created($this->users->create($input));
    }
}

final class DeleteUser implements Executable
{
    public function __construct(
        private readonly UserRepository $users,
    ) {}

    public function __invoke(ExecutionScope $scope): NoContent
    {
        /** @var RequestScope $scope */
        $this->users->delete($scope->params->get('id'));

        return new NoContent();
    }
}
```

Handlers that return `void` or `null` produce an empty 200 response. There is no wrapper for this case -- it is the implicit default.

## Request Validators

Validate body parameters inline with `RequestValidator`:

```php
<?php

use Phalanx\Http\RequestValidator;

final class MinLength implements RequestValidator
{
    public function __construct(private readonly int $min) {}

    public function __invoke(mixed $value): bool
    {
        return is_string($value) && strlen($value) >= $this->min;
    }
}
```

Use validators on any body accessor:

```php
<?php

$name = $scope->body->string('name', validate: new MinLength(3));
$age = $scope->body->int('age', validate: new Min(18));
$email = $scope->body->required('email', validate: new EmailFormat());
```

Failed validation throws `ValidationException` with `$e->errors` -- an `array<string, list<string>>` mapping field names to error messages. Validation results are cached per key+validator pair within the same `RequestBody` instance.

## WebSocket Integration

The HTTP runner handles WebSocket upgrades natively. See [phalanx/ws-server](../phalanx-ws-server/README.md) for the WebSocket API, then wire it in:

```php
<?php

use Phalanx\Http\Runner;
use Phalanx\WebSocket\WsRouteGroup;

Runner::from($app)
    ->withRoutes($httpRoutes)
    ->withWebsockets($wsRouteGroup)
    ->run();
```

HTTP and WebSocket traffic share a single TCP listener. The runner detects upgrade requests and routes them to the appropriate `WsRouteGroup`.

## OpenAPI Generation

`OpenApiGenerator` reflects on route handler signatures to produce an OpenAPI 3.1 spec. No running server is required -- generation is a pure static analysis pass over a `RouteGroup`.

```php
<?php

use Phalanx\Http\OpenApi\OpenApiGenerator;

$generator = new OpenApiGenerator(
    title: 'Task API',
    version: '2.0.0',
    description: 'Async task management',
);

$spec = $generator->generate($routes);

file_put_contents('openapi.json', json_encode($spec, JSON_PRETTY_PRINT));
```

What the generator derives from each route automatically:

- **Path parameters** -- extracted from `{name}` segments in the route pattern
- **Request body** -- reflected from the typed DTO parameter on `POST`/`PUT`/`PATCH` handlers; schema built from constructor parameter types
- **Query parameters** -- reflected from the typed DTO parameter on `GET`/`DELETE` handlers
- **Response status** -- inferred from the return type (`Created` → 201, `NoContent` → 204, etc.)
- **422 response** -- included automatically on any route with a typed input parameter
- **404 response** -- included automatically on any route with path parameters
- **Summary and tags** -- read from `SelfDescribed` and `Tagged` interfaces on the handler class if implemented

### Kubb integration

The generated spec is designed for consumption by [Kubb](https://kubb.dev/), which generates typed TypeScript clients and React Query hooks directly from an OpenAPI document:

```json
{
  "openapi": "3.1.0",
  "info": { "title": "Task API", "version": "2.0.0" },
  "paths": {
    "/tasks": {
      "post": {
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": {
                "type": "object",
                "properties": {
                  "title": { "type": "string" },
                  "description": { "type": "string", "nullable": true },
                  "priority": { "type": "integer" }
                },
                "required": ["title"]
              }
            }
          }
        },
        "responses": {
          "201": { "description": "Created" },
          "422": { "description": "Validation Failed" }
        }
      }
    }
  }
}
```

The spec round-trips cleanly: PHP constructor types become JSON Schema types, PHP return type annotations become response status codes, and route patterns become OpenAPI path templates.
