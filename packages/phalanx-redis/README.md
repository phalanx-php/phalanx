<p align="center">
  <img src="brand/logo.svg" alt="Phalanx" width="520">
</p>

# phalanx/redis

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

For commands without a typed method, use `raw()` or call them directly:

```php
<?php

// Explicit
$redis->raw('HSET', 'user:42', 'email', 'alice@example.com');
$redis->raw('HGETALL', 'user:42');

// Magic method fallback — same result, slightly nicer syntax
$redis->hset('user:42', 'email', 'alice@example.com');
$redis->hgetall('user:42');
```

Both paths resolve to the same underlying async call. Use whichever reads better at the call site.

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

`RedisPubSub` uses a dedicated connection for subscriptions, separate from the command client:

```php
<?php

$pubsub = $scope->service(RedisPubSub::class);

// Subscribe to a channel
$pubsub->subscribe('notifications', static function (string $message) {
    $event = json_decode($message, true);
    handleNotification($event);
});

// Publish from anywhere
$pubsub->publish('notifications', json_encode([
    'type' => 'order.shipped',
    'orderId' => $orderId,
]));
```

Multiple subscriptions run concurrently on the same connection. Publishing works from any scope that has access to `RedisPubSub`.

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
