# Phalanx Surreal

Native SurrealDB HTTP and live-query RPC integration for Phalanx through Iris, Hermes, and Aegis-managed waits.

## Usage

```php
<?php

use Phalanx\Application;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Surreal\Surreal;
use Phalanx\Surreal\SurrealBundle;
use Phalanx\Task\Task;

Application::starting([])
    ->providers(new SurrealBundle())
    ->run(Task::named('olympus.query', static function (ExecutionScope $scope): void {
        $surreal = $scope->service(Surreal::class);

        $surreal->create('oracle:apollo', [
            'name' => 'Apollo',
            'domain' => 'prophecy',
        ]);
    }));
```

The `SurrealBundle` registers `Surreal::class` as the library entry point. Resolve it from the active task scope instead of manually constructing a client.

`Surreal` wraps the HTTP RPC surface used by the current package:

- Connection/session: `signin`, `signup`, `authenticate`, `invalidate`, `reset`, `info`, `ping`, `version`, `status`, `health`, `use`
- Querying: `query`, `queryRaw`, `let`, `unset`
- Records: `select`, `create`, `insert`, `insertRelation`, `update`, `upsert`, `merge`, `patch`, `delete`, `relate`
- Functions/live query cleanup: `run`, `kill`, `rpc`
- Live queries: `live`, `liveQuery`

`let()` and `unset()` manage local HTTP query variables. Those variables are merged into later `query()` calls from this `Surreal` instance; they are not persistent WebSocket session variables.

`withDatabase()` returns a scoped variant for another namespace/database. `use()` sends Surreal's RPC `use` method and then updates this instance's local HTTP header context.

Live queries use Hermes' WebSocket client under the same scoped `Surreal` entry point. Once a live connection is open, session mutation methods such as `signin`, `authenticate`, `use`, `let`, and `unset` are rejected on that instance so the HTTP and WebSocket session state cannot diverge.

Run the in-memory SurrealDB demos:

```bash
composer demo:surreal
```

Run only the live-query demo:

```bash
composer demo:surreal:live
```

Part of the [Phalanx monorepo](https://github.com/phalanx-php/phalanx).
