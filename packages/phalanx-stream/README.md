<p align="center">
  <img src="https://raw.githubusercontent.com/havy-tech/phalanx/main/logo.svg" alt="Phalanx" width="520">
</p>

# Phalanx Stream

Reactive streams that bridge push-based sources into pull-based fiber iteration. WebSocket frames, SSE events, file tails, timers--they all become composable pipelines you consume with `foreach`.

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Creating Streams](#creating-streams)
  - [Custom Producers](#custom-producers)
  - [ReadableStream Sources](#readablestream-sources)
  - [Event Emitter Sources](#event-emitter-sources)
  - [Interval Streams](#interval-streams)
- [Operators](#operators)
- [Terminal Operations](#terminal-operations)
- [Backpressure](#backpressure)
- [Scoped Streams](#scoped-streams)
- [Lifecycle Hooks](#lifecycle-hooks)

## Installation

```bash
composer require phalanx/stream
```

Requires PHP 8.4+.

## Quick Start

```php
<?php

use Phalanx\Stream\Emitter;

// Create a stream from a producer
$prices = Emitter::produce(static function (Channel $ch) use ($exchange) {
    while ($price = $exchange->nextPrice()) {
        $ch->emit($price);
    }
});

// Compose a pipeline
$alerts = $prices
    ->filter(static fn($p) => $p->change > 0.05)
    ->throttle(seconds: 1.0)
    ->map(static fn($p) => new PriceAlert($p));

// Consume in a fiber -- reads like synchronous code
foreach ($alerts->consume() as $alert) {
    $notifier->send($alert);
}
```

No promise chains. No callback nesting. The `Emitter` handles the async coordination; your code reads top-to-bottom.

## Creating Streams

### Custom Producers

`Emitter::produce()` is the general-purpose factory. Your callable receives a `Channel` for emitting values and a `StreamContext` for cleanup registration:

```php
<?php

$stream = Emitter::produce(static function (Channel $ch, StreamContext $ctx) {
    $conn = new WebSocketConnection($url);

    $ctx->onDispose(static fn() => $conn->close());

    while ($frame = $conn->receive()) {
        $ch->emit($frame);
    }

    $ch->complete();
});
```

Call `$ch->emit()` to push values. Call `$ch->complete()` when done. Call `$ch->error($e)` to terminate with a failure. If your producer throws, the channel automatically completes with that error.

### ReadableStream Sources

Wrap any ReactPHP `ReadableStreamInterface` directly. Backpressure propagates automatically--when the channel buffer fills, the source stream pauses; when the consumer catches up, it resumes:

```php
<?php

$logStream = Emitter::stream($process->stdout);

$errors = $logStream
    ->filter(static fn(string $line) => str_contains($line, 'ERROR'))
    ->map(static fn(string $line) => LogEntry::parse($line));
```

### Event Emitter Sources

Bridge any Evenement `EventEmitterInterface` into a stream. Specify which event to listen for:

```php
<?php

$clicks = Emitter::listen('click', $uiComponent);
$messages = Emitter::listen('message', $webSocket);
```

The emitter subscribes to the named event and forwards payloads to the channel. Error and close events are handled automatically.

### Interval Streams

Emit sequential tick values on a timer:

```php
<?php

$heartbeat = Emitter::interval(5.0);

// Emits 1, 2, 3, ... every 5 seconds
foreach ($heartbeat->take(10)->consume() as $tick) {
    $monitor->ping(['tick' => $tick, 'ts' => time()]);
}
```

## Operators

Operators return new `Emitter` instances--the original stream is unchanged. Chain them to build pipelines:

```php
<?php

$pipeline = $source
    ->filter(static fn($v) => $v > 0)           // drop non-positive values
    ->map(static fn($v) => $v * 100)             // transform
    ->distinct()                                  // drop consecutive duplicates
    ->throttle(seconds: 0.5)                      // at most one value per 500ms
    ->take(50);                                   // stop after 50 values
```

| Operator | Effect |
|----------|--------|
| `filter(fn)` | Keep values where predicate returns true |
| `map(fn)` | Transform each value |
| `take(n)` | First N values, then complete |
| `throttle(sec)` | At most one value per interval |
| `debounce(sec)` | Emit after a pause in emissions |
| `bufferWindow(count, sec)` | Collect into arrays, flush on count or time |
| `merge(Emitter...)` | Interleave multiple streams into one |
| `distinct()` | Drop consecutive duplicates |
| `distinctBy(fn)` | Drop consecutive duplicates by key |
| `sample(sec)` | Sample latest value at fixed interval |

```php
<?php

// Merge multiple event sources
$allEvents = $userEvents->merge($systemEvents, $auditEvents);

// Buffer into batches for efficient DB writes
$batched = $events->bufferWindow(count: 100, seconds: 2.0);
foreach ($batched->consume() as $batch) {
    $db->insertBatch($batch);
}

// Sample a high-frequency sensor at 1Hz
$readings = $sensorStream->sample(seconds: 1.0)->take(3600);
```

## Terminal Operations

Terminals consume the stream and produce a final value. They drive iteration to completion:

```php
<?php

// Collect everything into an array
$all = $stream->toArray();

// Reduce to a single value
$sum = $stream->reduce(static fn($acc, $v) => $acc + $v, initial: 0);

// Get the first value
$first = $stream->first();

// Consume for side effects (foreach without the foreach)
$stream->consume();
```

Terminal operations return task-like objects that execute against a `StreamContext`. When using `ScopedStream`, terminals execute immediately and return the result.

## Backpressure

The `Channel` is the backpressure mechanism. It holds a bounded buffer (default: 32 items). When the buffer fills:

1. The producer suspends (its fiber yields at the `emit()` call)
2. The consumer drains values through `consume()`
3. When the buffer drops below 50% capacity, the producer resumes

For `ReadableStreamInterface` sources, backpressure maps to `pause()` and `resume()` calls on the underlying stream. No unbounded buffering. No dropped values. The fast producer waits for the slow consumer.

```php
<?php

// Channel with custom buffer size
$stream = Emitter::produce(static function (Channel $ch, StreamContext $ctx) {
    // Channel's default buffer is 32 items
    // Producer automatically suspends when buffer fills
    foreach ($hugeDataset as $row) {
        $ch->emit($row);  // suspends here if buffer is full
    }
    $ch->complete();
});
```

## Scoped Streams

`ScopedStream` binds a stream to an `ExecutionScope`. It inherits the scope's cancellation token, and cleanup runs when the scope disposes:

```php
<?php

use Phalanx\Stream\ScopedStream;

$stream = ScopedStream::from($scope, static function (Channel $ch, StreamContext $ctx) {
    while ($msg = $queue->receive()) {
        $ch->emit($msg);
    }
});

$recent = $stream
    ->filter(static fn($msg) => $msg->priority === 'high')
    ->take(100)
    ->toArray();

// Stream cleanup happens automatically when $scope->dispose() runs
```

`ScopedStream` mirrors the full operator API (`map`, `filter`, `throttle`, etc.) and provides direct terminal methods that execute immediately against the bound context.

## Lifecycle Hooks

Attach callbacks to stream lifecycle events for logging, metrics, or resource management:

```php
<?php

$stream = Emitter::produce($myProducer)
    ->onStart(static fn(StreamContext $ctx) => $metrics->increment('stream.started'))
    ->onEach(static fn($value, StreamContext $ctx) => $metrics->increment('stream.items'))
    ->onError(static fn(\Throwable $e, StreamContext $ctx) => $logger->error($e->getMessage()))
    ->onComplete(static fn(StreamContext $ctx) => $metrics->increment('stream.completed'))
    ->onDispose(static fn(StreamContext $ctx) => $metrics->increment('stream.disposed'));
```

Hooks compose through the operator chain. Each operator carries forward the hooks from its parent, so a `filter()->map()->onEach()` pipeline fires `onEach` for values that survive the filter and transform.
