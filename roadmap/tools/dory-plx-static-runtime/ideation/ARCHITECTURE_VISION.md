# Dory: The Unified Architecture Vision

This document outlines the foundational architecture and roadmap for Dory. The goal is to prevent patchwork development by establishing how Dory will integrate with Phalanx's core primitives (Pooling, Tracing, Ledgers), how it will normalize data for intelligent rendering, and how it will honor its Unix terminal heritage (Piping, Streams).

## 1. The Rendering Pipeline & Type Normalization

Drawing inspiration from `psy/psysh` and Laravel Tinker, Dory will not just dump raw object properties. Because Phalanx enforces rigid, disciplined types, Dory can intelligently render them.

### The `DoryRenderable` Contract
We will introduce a rendering normalization layer. Any object returned by a Dory script or passed to `$dory->dump()` will pass through a caster pipeline.

*   **Collections to Tables:** If a script reads a JSON file representing an array of structured objects, `$dory->dump($json)` won't print a massive nested array. It will automatically detect uniform structures and render an Archon CLI Table.
*   **Settlements:** When dumping a `SettlementBag` (from `$dory->settle()`), Dory will render a visual summary: `[ ✅ 4 Succeeded | ❌ 1 Failed ]`, followed by a formatted list of the actual errors, hiding the internal boilerplate of the settlement objects.
*   **Traces:** Dumping a `Trace` object will render a visual flamegraph or tree in the terminal, showing execution times of spans rather than a raw array of timestamps.

## 2. Unix Heritage: Streams, Pipes, and Chains

A terminal tool must play nicely in a pipeline (`cat data.json | dory process.php | jq`). Dory will map PHP's `STDIN`/`STDOUT` to Phalanx `Styx` streams, enabling push/pull semantics and backpressure.

### The Pipeline Abstraction
Data arriving via `STDIN` or network interfaces (Redis, HTTP) will be normalized into a `DoryStream` (wrapping a Styx `Emitter` or `Channel`).

```php
// process.php
// $dory->input is an async stream of STDIN lines
$dory->input
    ->filter(fn($line) => str_contains($line, 'ERROR'))
    ->map(fn($line) => $dory->json->parse($line))
    ->concurrentMap(fn($data) => $dory->http->post('...', $data)) // Processed concurrently!
    ->pipe($dory->output); // Streams safely to STDOUT
```
Because this is backed by `Styx` and OpenSwoole, the script will process gigabytes of piped data with a tiny, flat memory footprint, applying backpressure automatically if the HTTP POST is too slow.

## 3. Exposing the Aegis Core: Pooling, Ledgers, and Tracing

Dory scripts are first-class Phalanx citizens. They run inside the Aegis Kernel, which means we must expose its superpowers cleanly.

### Object Pooling (`$dory->pool`)
For high-performance scripts (like processing a million rows), allocating objects kills performance. Dory will expose Aegis's `ZMM` (Zend Memory Manager) aware object pools.

```php
// Borrow a pre-allocated parser object from the pool
$parser = $dory->pool->acquire(HeavyParser::class);
try {
    $result = $parser->parse($data);
} finally {
    // Return it to the pool
    $dory->pool->release($parser);
}
```

### The Ledger & Active Supervision (`$dory->ledger`)
The Aegis supervisor maintains a Swoole Table ledger of every active task, coroutine, and lease. A Dory script can inspect its own state or the state of child workers.

```php
// A script that monitors itself
$dory->time->every(5.0, function() use ($dory) {
    // Automatically renders a table of all active coroutines,
    // what wait reason they are suspended on, and their memory usage.
    $dory->dump($dory->ledger->snapshot());
});
```

### Tracing (`$dory->trace`)
Every Dory script automatically opens a root trace span. Developers can add sub-spans effortlessly to find bottlenecks.

```php
$dory->trace->span('database_sync', function() use ($dory) {
    // ... heavy work ...
});
// When the script finishes, Dory can optionally print a summary of all trace spans.
```

## 4. Domain-Specific Normalization

To allow Dory to evolve without breaking scripts, we will normalize related domains into unified interfaces.

*   **The Network Domain (`$dory->net`):** Normalizes SSH (`enigma`), SCP, raw TCP/UDP sockets, and basic HTTP checks under one semantic umbrella. They all return unified `ConnectionResult` or `CommandResult` types.
*   **The IPC / Data Domain (`$dory->data`):** Normalizes Redis, Postgres, and local SQLite interactions. A query to Postgres or Redis will return a normalized `RecordSet` that the Dory rendering pipeline inherently knows how to format as a table or stream to `STDOUT`.

## Summary
By rigorously enforcing return types (Records, Settlements, Streams) rather than raw arrays or boolean flags, Dory's environment can intercept, format, and pipe data intelligently. The script writer focuses purely on the business logic, while Dory handles the terminal ergonomics.
