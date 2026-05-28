# Dory Power-Scripting: Advanced Concurrency & Extensibility

## 1. Extending `$dory` (The DX Extensibility Model)

Dory needs to be extensible without becoming a bloated framework. We can achieve this by allowing the developer to register custom facades or macros onto the `ScriptScope` dynamically.

Because Dory relies on the Aegis Service Container, extending `$dory` is simply a matter of registering a closure or an object to the underlying container, exposed via a `->extend()` or `->macro()` method.

### The Macro Pattern
```php
// Register a custom utility for the life of this script
$dory->macro('ffmpeg', function (string $input, string $output) use ($dory) {
    // Spawns a background process, supervised by Dory
    return $dory->exec("ffmpeg -i {$input} -c:v libx264 {$output}");
});

// Use it seamlessly
$dory->ffmpeg('video.mp4', 'output.mp4');
```

### The FFI / C-Extension Pattern
Since Dory is statically compiled via `spc`, we can bake in `ext-ffi` and extensions like `ext-gd` or `ext-vips`.
With FFI (Foreign Function Interface), Dory can bind directly to system libraries (like SQLite, libvips, or Rust DLLs) without writing C-code extensions.

```php
$dory->macro('sqlite', function(string $dbPath) {
    // FFI binding directly to libsqlite3.so
    $ffi = FFI::cdef("int sqlite3_open(const char *filename, void **ppDb);", "libsqlite3.so");
    // ... Returns a high-ergonomic wrapper
});
```

Because Aegis lazily instantiates services, if a script never calls `$dory->ffmpeg` or `$dory->sqlite`, the memory footprint remains absolutely minimal. You don't have to "remove" functionality—it simply slumbers until invoked.

---

## 2. Advanced Aegis Primitives

Dory isn't just about `concurrent()`. It exposes the entire Phalanx supervisor suite, bringing enterprise-grade orchestration to single-file scripts.

### `$dory->race()`
Launch multiple operations simultaneously, but only keep the result of the first one to finish. Aegis automatically **cancels** the losers so you don't waste bandwidth or memory.

```php
echo "Finding the fastest mirror...\n";

$fastestMirror = $dory->race(
    us_east: fn() => $dory->http->get('https://us-east.example.com/data.json'),
    eu_west: fn() => $dory->http->get('https://eu-west.example.com/data.json'),
    ap_south: fn() => $dory->http->get('https://ap-south.example.com/data.json')
);

$dory->dump("Won the race:", $fastestMirror->body);
```

### `$dory->singleflight()`
A lifesaver for cache stampedes or redundant work. If 50 coroutines try to fetch the same data, `singleflight` guarantees the actual work only happens **once**, and all 50 coroutines receive the exact same result.

```php
// Imagine this is called in a loop or by many concurrent workers
$fetchConfig = function() use ($dory) {
    return $dory->singleflight('fetch-remote-config', function() use ($dory) {
        $dory->dump("Actually hitting the network...");
        return $dory->http->get('https://api.example.com/config')->json();
    });
};

// 10 concurrent requests, but "Actually hitting the network..." only prints ONCE.
$configs = $dory->concurrent(
    $fetchConfig, $fetchConfig, $fetchConfig, $fetchConfig, ...
);
```

### `$dory->retry()`
Transient failures (network blips, rate limits) crash normal scripts. Dory handles this beautifully with supervised backoff.

```php
use Phalanx\Concurrency\RetryPolicy;

// Try up to 5 times, with exponential backoff (e.g. 100ms, 200ms, 400ms...)
$data = $dory->retry(
    function() use ($dory) {
        $response = $dory->http->get('https://flaky-api.com/status');
        if ($response->status === 503) {
            throw new \RuntimeException("Service Unavailable");
        }
        return $response->json();
    },
    RetryPolicy::exponential(maxAttempts: 5, initialDelay: 0.1)
);
```

### `$dory->waterfall()`
When you have a series of async operations where step 2 depends on the output of step 1, but you still want Aegis to supervise (and potentially timeout/cancel) the entire chain.

```php
$userProfile = $dory->waterfall(
    // 1. Fetch user ID
    fn() => $dory->http->get('https://api.example.com/me')->json()['id'],

    // 2. Fetch user's orders using that ID
    fn(string $id) => $dory->http->get("https://api.example.com/users/{$id}/orders")->json(),

    // 3. Process the orders
    fn(array $orders) => $dory->calculateTotalSpend($orders)
);
```

### `$dory->timeout()`
Never let a script hang indefinitely.

```php
try {
    // If the DB takes longer than 2.5 seconds, it cancels the internal execution
    // and throws a TimeoutException.
    $dory->timeout(2.5, function() use ($dory) {
        return $dory->exec('pg_dump ...');
    });
} catch (\Phalanx\Cancellation\Cancelled $e) {
    $dory->dump("Database dump timed out!");
}
```
