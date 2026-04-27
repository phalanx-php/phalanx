<p align="center">
  <img src="brand/logo.svg" alt="Phalanx" width="520">
</p>

# phalanx/redis

> **Phalanx** is a first-principles rethinking of what PHP can be when modern language features and a decade of async community work are treated as the foundation, not an afterthought. [Read more](https://github.com/phalanx-php/phalanx-aegis#phalanx-aegis---async-php) in the core library.

Async Redis with typed commands, pub/sub, and automatic connection management — fully non-blocking, fully integrated with Phalanx scopes and services.

## Installation

```bash
composer require phalanx/redis
```

Requires PHP 8.4+.

## Setup

Register the service bundle when building your application:

```php
<?php

use Phalanx\Redis\RedisServiceBundle;

$app = Application::starting()
    ->providers(new RedisServiceBundle())
    ->compile();
```

`RedisServiceBundle` registers `RedisClient` and `RedisPubSub` as singletons. Both shut down cleanly when the scope tears down.

## Configuration

`RedisConfig` accepts a URL or individual parameters:

```php
<?php

// URL
RedisConfig::fromUrl('redis://:secret@localhost:6379/0');

// Individual params
new RedisConfig(
    host: 'localhost',
    port: 6379,
    password: 'secret',
    database: 0,
);
```

Falls back to `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `REDIS_DATABASE` environment variables when no explicit values are provided.

## Commands

`RedisClient` wraps `clue/redis-react` with typed methods for common operations. It accepts `Suspendable` in its constructor (the narrowest scope interface needed for `await()`), and all Redis commands suspend through `$scope->await()` for scope-managed cancellation.

```php
<?php

$redis = $scope->service(RedisClient::class);

// Strings
$redis->set('user:42:name', 'Alice');
$name = $redis->get('user:42:name'); // 'Alice'

// Expiration
$redis->set('session:abc', $token);
$redis->expire('session:abc', 3600);

// Counters
$redis->incr('page:views');
$redis->decr('stock:item:99');

// Key management
$redis->exists('user:42:name'); // true
$redis->del('session:abc', 'session:def');
```

### Any Redis Command

For commands without a typed method, use `raw()`. It returns a promise, so wrap it in `$scope->await()` when you need the result synchronously:

```php
<?php

// raw() returns a PromiseInterface — use scope->await() for the result
$scope->await($redis->raw('HSET', 'user:42', 'email', 'alice@example.com'));
$hash = $scope->await($redis->raw('HGETALL', 'user:42'));

// Any Redis command works through raw()
$scope->await($redis->raw('LPUSH', 'queue:jobs', json_encode($job)));
$scope->await($redis->raw('SADD', 'tags', 'php', 'async'));
```

## Caching Patterns

```php
<?php

$redis = $scope->service(RedisClient::class);

// Cache-aside with JSON
$key = "user:{$userId}:profile";
$cached = $redis->get($key);

if ($cached !== null) {
    return json_decode($cached, true);
}

$profile = $scope->service(PgPool::class)->query(
    'SELECT * FROM profiles WHERE user_id = $1',
    [$userId]
);

$redis->set($key, json_encode($profile));
$redis->expire($key, 3600);

return $profile;
```

Combine with Phalanx's concurrent execution to warm multiple cache keys at once:

```php
<?php

$scope->concurrent([
    Task::of(static fn($s) => warmCache($s, 'user:1:profile')),
    Task::of(static fn($s) => warmCache($s, 'user:2:profile')),
    Task::of(static fn($s) => warmCache($s, 'user:3:profile')),
]);
```

## Pub/Sub

`RedisPubSub` uses a dedicated connection for subscriptions, separate from the command client. `subscribe()` returns an `Emitter` that yields `['channel' => string, 'message' => string]` arrays:

```php
<?php

use Phalanx\Stream\ScopedStream;

$pubsub = $scope->service(RedisPubSub::class);

// subscribe() returns an Emitter — consume it with a ScopedStream or foreach
$stream = ScopedStream::from($scope, $pubsub->subscribe('notifications'));

foreach ($stream as $item) {
    $event = json_decode($item['message'], true);
    handleNotification($event);
}
```

Publishing goes through `RedisClient::raw()` since it's a standard command, not a subscription:

```php
<?php

$redis = $scope->service(RedisClient::class);

$scope->await($redis->raw('publish', 'notifications', json_encode([
    'type' => 'order.shipped',
    'orderId' => $orderId,
])));
```

### Scoped Subscriptions

`subscribeEach()` runs a handler per message in a fresh child scope. Each message gets its own scoped services, disposed automatically after the handler completes:

```php
<?php

use Phalanx\Redis\RedisPubSub;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;

final class OrderHandler implements Executable
{
    public function __invoke(ExecutionScope $scope): mixed
    {
        $message = $scope->attribute('subscription.message');
        $channel = $scope->attribute('subscription.channel');

        $order = json_decode($message, true);
        $scope->service(OrderProcessor::class)->process($order);

        return null;
    }
}
```

```php
<?php

$pubsub = $scope->service(RedisPubSub::class);
$pubsub->subscribeEach('orders', new OrderHandler(), $scope);
```

The handler receives `subscription.message` and `subscription.channel` as scope attributes. Parent cancellation stops the subscription loop.

## Shutdown

`RedisServiceBundle` registers disposal hooks on the scope. When the scope tears down, connections close and subscriptions unsubscribe. No manual cleanup required.
