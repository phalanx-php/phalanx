<p align="center">
  <img src="assets/banner.svg" alt="Phalanx" width="520">
</p>

# Phalanx Dory

Scripting runtime for Phalanx. Dory runs PHP scripts inside a managed Aegis scope with full access to concurrency, cancellation, service resolution, and the framework's module ecosystem.

Part of the [Phalanx framework](https://github.com/phalanx-php/phalanx).

## What it does

Dory turns standalone PHP scripts into supervised Phalanx programs. Each script runs inside an `ExecutionScope` with access to HTTP clients, file I/O, task orchestration, and any registered service bundle -- the same runtime surface that Stoa HTTP handlers and Archon CLI commands use.

Scripts access their execution context through the global `dory()` helper:

```php
<?php

dory()->println('Greetings from Olympus.');

$result = dory()->attempt(static fn(): string => 'The phalanx holds.')
    ->timeout(5.0)
    ->run();

dory()->dump($result);
```

## Script context

`dory()` returns a `ScriptContext`, which extends `ExecutionScope`. In addition to the full scope surface (`concurrent()`, `map()`, `retry()`, `service()`, etc.), it provides:

| Method / Property | Purpose |
|---|---|
| `dory()->println($msg)` | Write a line to the script's output sink |
| `dory()->dump(...$values)` | Render values through the configured pipeline |
| `dory()->attempt($task)` | Build a retry/timeout chain via `AttemptBuilder` |
| `dory()->http` | Lazy-resolved HTTP client (requires `phalanx-iris`) |
| `dory()->fs` | Lazy-resolved file facade (requires `phalanx-grammata`) |
| `dory()->scriptPath` | Absolute path to the running script |
| `dory()->scriptName` | Basename of the script file |
| `dory()->config` | `DoryConfig` instance |

Module services (`http`, `fs`) resolve lazily on first access. If the backing package is not installed, access throws a service resolution error.

## Configuration

`DoryConfig` binds to environment variables through `#[Env]` attributes:

| Env var | Default | Description |
|---|---|---|
| `DORY_SCRIPT_TIMEOUT` | `30.0` | Maximum script runtime in seconds |
| `DORY_MAX_CONCURRENCY` | `50` | Maximum concurrent tasks per script |
| `DORY_VERBOSE` | `false` | Enable verbose script output |

## CLI commands

Dory registers commands under the `dory` binary:

| Command | Description |
|---|---|
| `dory run <script>` | Execute a Dory script |
| `dory init [dir]` | Scaffold a new Dory project with a sample script |
| `dory doctor` | Check environment readiness |

Two additional command groups are conditionally available:

- `dory build ...` -- available when `phalanx-dorybin` is installed (static binary compilation)
- `dory serve` -- available when `phalanx-skopos` is installed (dev server with file watching)
