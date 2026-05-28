# Dory Power-Scripting Ideation

This document explores concrete use cases for the Dory scripting environment, demonstrating how the Phalanx kernel, OpenSwoole hooks, and Dory Prelude combine to create a low-ceremony, high-power systems tool.

## The Core Philosophy
1. **Zero Setup:** No `composer.json`, no `vendor/autoload.php` in the script.
2. **Strict PHP 8.4:** `declare(strict_types=1);` is implicit (enforced by the runtime wrapper). All closures are `static fn()`.
3. **Implicit Async:** Standard PHP functions (`file_get_contents`, `PDO`, `sleep`) are intercepted by OpenSwoole hooks and yield correctly to the Aegis supervisor.
4. **The `$dory` Scope:** The global `$dory` variable provides access to Aegis orchestration (`concurrent`, `singleflight`, `settle`) and native Phalanx services (`http`, `fs`, `ssh`).

---

## Use Case 1: The "Cache Stampede" Buster (Data Aggregation)

**Scenario:** We need to fetch a remote configuration file to process 100 local JSON files. We want to process the files concurrently, but we MUST ensure we only hit the remote API exactly once, even if 100 coroutines request it simultaneously.

```php
// Use the Prelude's alias for the WaitReason class if needed,
// though $dory methods encapsulate most needs.

$dory = $GLOBALS['dory'];

// Define a safe, singleflight fetcher
$getConfig = static fn() => $dory->singleflight('remote-config', static function() use ($dory) {
    $dory->dump("Hitting network for config... (This should only print ONCE)");
    // Implicit async HTTP call via native Phalanx Iris
    return $dory->http->get($dory, 'https://api.internal.lan/v1/config')->json();
});

// Find all files (Implicit async glob)
$files = glob('/var/data/incoming/*.json');

// Process all files concurrently, limiting to 20 at a time to prevent CPU starvation
$results = $dory->map($files, static function(string $file) use ($dory, $getConfig) {
    // 1. Get config (100 coroutines will ask, only 1 network request happens)
    $config = $getConfig();

    // 2. Read and parse file (Implicit async file I/O)
    $data = json_decode(file_get_contents($file), true);

    // 3. Process...
    return [
        'file' => basename($file),
        'status' => $data['version'] >= $config['min_version'] ? 'valid' : 'outdated'
    ];
}, limit: 20);

$dory->ui->table($results);
```

---

## Use Case 2: The Resilient Multi-Region Deployer (Network Orchestration)

**Scenario:** We need to trigger a deployment webhook across 3 geographic regions. The network is flaky. We want to race them (fastest wins for local status), but ensure ALL of them eventually complete using exponential backoff.

```php
$dory = $GLOBALS['dory'];

$regions = [
    'us-east' => 'https://us.deploy.lan/hook',
    'eu-west' => 'https://eu.deploy.lan/hook',
    'ap-south' => 'https://ap.deploy.lan/hook'
];

$dory->dump("Triggering global deployment...");

// 1. Create a reusable, resilient task using the Prelude's `exp_backoff()` helper
$deployTask = static fn(string $url) => $dory->retry(
    static function() use ($dory, $url) {
        $resp = $dory->http->post($dory, $url, ['version' => 'v1.4.2']);
        if ($resp->status !== 200) {
            throw new \RuntimeException("Deployment failed at $url");
        }
        return $resp->body;
    },
    exp_backoff(attempts: 5, delay: 0.5) // From Dory Prelude
);

// 2. Use ->settle() so a failure in one region doesn't cancel the others.
// SettlementBag handles the aggregate success/fail state.
$settlements = $dory->settle(
    us: static fn() => $deployTask($regions['us-east']),
    eu: static fn() => $deployTask($regions['eu-west']),
    ap: static fn() => $deployTask($regions['ap-south'])
);

if ($settlements->hasFailures()) {
    $dory->dump("WARNING: Some regions failed to deploy!");
    foreach ($settlements->failures() as $region => $error) {
        $dory->dump("[$region] Error: " . $error->getMessage());
    }
} else {
    $dory->dump("All regions deployed successfully.");
}
```

---

## Use Case 3: The "Circuit Breaker" Health Checker (Control Flow)

**Scenario:** A cron job runs every minute to check database health. If it takes longer than 2 seconds, it should be killed immediately to prevent piling up zombies. If it fails, we want to catch the error cleanly without huge try/catch blocks.

```php
$dory = $GLOBALS['dory'];

// Using the proposed `attempt()` primitive for clean control flow
$healthCheck = $dory->attempt(static function() use ($dory) {

    // Timeout enforcement: cancels the internal scope if it exceeds 2.0s
    return $dory->timeout(2.0, static function() use ($dory) {
        // Assume PDO is available in the static binary and hooked by OpenSwoole
        $pdo = new PDO('pgsql:host=db.internal;dbname=main', 'user', 'pass');
        $stmt = $pdo->query("SELECT 1");
        return $stmt !== false;
    });

});

if ($healthCheck->isFailed()) {
    $error = $healthCheck->error();

    if ($error instanceof \Phalanx\Cancellation\Cancelled) {
        $dory->dump("CRITICAL: Database health check timed out!");
    } else {
        $dory->dump("CRITICAL: Database connection failed: " . $error->getMessage());
    }

    // Alert Slack (Fire and forget, we don't wait for the response)
    $dory->defer(static fn() => $dory->http->post($dory, 'https://slack.com/webhook', ['text' => 'DB DOWN']));

    exit(1);
}

$dory->dump("Database is healthy.");
```

---

## Use Case 4: The Anonymous Worker (Data Processing)

**Scenario:** We need to resize a directory of images. This is CPU-bound, so coroutines won't help (they would block the event loop). We need to spawn actual child worker processes, but we don't want the ceremony of creating separate worker classes/files.

```php
$dory = $GLOBALS['dory'];

$images = glob('./uploads/*.png');

// Use an anonymous class implementing Phalanx\Worker\WorkerTask
// This is perfectly valid PHP 8 and keeps the script strictly typed.
$resizeTask = new class implements \Phalanx\Worker\WorkerTask {
    public function __construct(private readonly string $file) {}

    public function execute(): string {
        // This runs in a separate child process!
        // Assuming ext-vips or ext-gd is compiled into the Dory binary
        $img = vips_image_new_from_file($this->file);
        $out = str_replace('.png', '-thumb.png', $this->file);
        vips_image_write_to_file($img, $out);
        return $out;
    }
};

$dory->dump("Processing " . count($images) . " images across CPU cores...");

// Map the array of files to the anonymous task, executing in parallel worker processes
$results = $dory->mapParallel($images, static fn(string $file) => new $resizeTask($file));

$dory->dump("Resized images:", $results);
```

---

## Use Case 5: The Interactive Supervisor (REPL / Terminal)

**Scenario:** A complex database migration script requires manual confirmation at various stages. We use `waterfall` (where each step gets a fresh child scope) and the Archon UI utilities.

```php
$dory = $GLOBALS['dory'];

$dory->dump("Starting Multi-Stage Migration");

$finalState = $dory->waterfall(

    // Step 1: Pre-flight check
    static function() use ($dory) {
        $dory->dump("Step 1: Checking schema...");
        // ... work ...
        return ['version' => 12];
    },

    // Step 2: Confirmation (receives output of Step 1)
    static function(array $state) use ($dory) {
        if (!$dory->ui->confirm("Current version is {$state['version']}. Proceed with migration?")) {
            // Cancel the entire waterfall gracefully
            $dory->cancellation()->cancel();
        }
        return $state;
    },

    // Step 3: Execution
    static function(array $state) use ($dory) {
        $dory->dump("Step 3: Migrating...");
        // ... heavy work ...
        return true;
    }
);

$dory->dump("Migration Complete.");
```
