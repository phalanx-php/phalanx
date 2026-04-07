<p align="center">
  <img src="brand/logo.svg" alt="Phalanx" width="520">
</p>

# phalanx/postgres

> **Phalanx** is a first-principles rethinking of what PHP can be when modern language features and a decade of async community work are treated as the foundation, not an afterthought. [Read more](https://github.com/havy-tech/phalanx-core#phalanx-core---async-php) in the core library.

Async PostgreSQL with connection pooling, prepared statements, transactions, and LISTEN/NOTIFY — all non-blocking, all composable with Phalanx's concurrency primitives.

## Installation

```bash
composer require phalanx/postgres
```

Requires `ext-pgsql` and PHP 8.4+.

## Setup

Register the service bundle when building your application:

```php
<?php

use Phalanx\Postgres\PgServiceBundle;

$app = Application::starting()
    ->providers(new PgServiceBundle())
    ->compile();
```

`PgServiceBundle` registers `PgPool` and `PgListener` as singletons with automatic shutdown hooks. Connections close cleanly when the scope tears down.

## Configuration

`PgConfig` accepts a DSN string or individual parameters:

```php
<?php

// DSN
PgConfig::fromDsn('postgresql://user:pass@localhost:5432/mydb');

// Individual params
new PgConfig(
    host: 'localhost',
    port: 5432,
    user: 'app',
    password: 'secret',
    database: 'mydb',
    maxConnections: 10,
    idleTimeout: 30,
);
```

Environment variables work out of the box — `PgConfig` resolves `PG_HOST`, `PG_PORT`, `PG_USER`, `PG_PASSWORD`, `PG_DATABASE` when no explicit values are provided.

## Queries and Execution

`PgPool` wraps Amphp's `PostgresConnectionPool` with a clean async interface:

```php
<?php

$pg = $scope->service(PgPool::class);

// Parameterized query — returns PostgresResult
$users = $pg->execute(
    'SELECT * FROM users WHERE active = $1 LIMIT $2',
    [true, 50]
);

// Simple query (no parameters)
$all = $pg->query('SELECT * FROM users');

// Non-SELECT statements
$pg->execute(
    'UPDATE users SET last_seen = NOW() WHERE id = $1',
    [$userId]
);

// Prepared statements — parse once, execute many
$stmt = $pg->prepare('INSERT INTO events (type, payload) VALUES ($1, $2)');
foreach ($events as $event) {
    $stmt->execute([$event->type, json_encode($event->data)]);
}
```

Every query uses parameterized placeholders (`$1`, `$2`, ...). No string interpolation, no injection surface.

## Concurrent Queries

Because `PgPool` manages multiple connections, you can run queries concurrently through Phalanx's concurrency primitives:

```php
<?php

[$users, $orders, $stats] = $scope->concurrent([
    Task::of(static fn($s) => $s->service(PgPool::class)->query('SELECT * FROM users')),
    Task::of(static fn($s) => $s->service(PgPool::class)->query('SELECT * FROM orders')),
    Task::of(static fn($s) => $s->service(PgPool::class)->query('SELECT count(*) FROM events')),
]);
```

Three queries, three connections, one await. The pool handles connection checkout and return automatically.

## Transactions

```php
<?php

$pg = $scope->service(PgPool::class);

$tx = $pg->beginTransaction();
try {
    $tx->execute('INSERT INTO orders (user_id, total) VALUES ($1, $2)', [$userId, $total]);
    $tx->execute('UPDATE inventory SET qty = qty - $1 WHERE sku = $2', [$qty, $sku]);
    $tx->commit();
} catch (\Throwable $e) {
    $tx->rollback();
    throw $e;
}
```

The transaction holds a dedicated connection from the pool until committed or rolled back.

## LISTEN/NOTIFY

PostgreSQL's LISTEN/NOTIFY turns your database into a lightweight message broker. `PgListener` provides a dedicated connection for subscriptions so notifications never compete with query traffic.

```php
<?php

use Phalanx\Stream\ScopedStream;

$listener = $scope->service(PgListener::class);

// listen() returns an Emitter that yields payload strings
$stream = ScopedStream::from($scope, $listener->listen('order_created'));

foreach ($stream as $payload) {
    $order = json_decode($payload, true);
    processOrder($order);
}
```

Publishing from any connection:

```php
<?php

$pg = $scope->service(PgPool::class);
$pg->notify('order_created', json_encode(['id' => $orderId, 'total' => $total]));
```

Combine LISTEN with Phalanx's concurrency to fan out event handling across multiple channels without blocking:

```php
<?php

$scope->concurrent([
    Task::of(static fn($s) => handleChannel($s, 'order_created')),
    Task::of(static fn($s) => handleChannel($s, 'payment_received')),
    Task::of(static fn($s) => handleChannel($s, 'inventory_low')),
]);
```

## Shutdown

`PgServiceBundle` registers disposal hooks on the scope. When the scope tears down — whether from normal completion, cancellation, or error — open connections drain and close. No manual cleanup required.
