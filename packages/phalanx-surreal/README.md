# Phalanx Surreal

Native SurrealDB HTTP JSON RPC integration for Phalanx through Iris and Aegis-managed outbound HTTP waits.

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
    ->run(Task::named('athena.query', static function (ExecutionScope $scope): void {
        $surreal = $scope->service(Surreal::class);

        $surreal->create('goddess:athena', [
            'name' => 'Athena',
            'domain' => 'wisdom',
        ]);
    }));
```

The `SurrealBundle` registers `Surreal::class` as the library entry point. Resolve it from the active task scope instead of manually constructing a client.

`Surreal` wraps the HTTP RPC surface used by the current package:

- Connection/session: `signin`, `signup`, `authenticate`, `invalidate`, `reset`, `info`, `ping`, `version`, `status`, `health`, `use`
- Querying: `query`, `queryRaw`, `let`, `unset`
- Records: `select`, `create`, `insert`, `insertRelation`, `update`, `upsert`, `merge`, `patch`, `delete`, `relate`
- Functions/live query cleanup: `run`, `kill`, `rpc`

`let()` and `unset()` manage local HTTP query variables. Those variables are merged into later `query()` calls from this `Surreal` instance; they are not persistent WebSocket session variables.

`withDatabase()` returns a scoped variant for another namespace/database. `use()` sends Surreal's RPC `use` method and then updates this instance's local HTTP header context.

Run the in-memory SurrealDB demo:

```bash
composer demo:surreal
```

Part of the [Phalanx monorepo](https://github.com/phalanx-php/phalanx).
