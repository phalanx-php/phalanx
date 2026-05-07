<p align="center">
  <img src="logo.svg" alt="Phalanx" width="520">
</p>

# Phalanx

**Async PHP that feels like normal code — protected by a runtime that actually has your back.**

The async PHP landscape is crowded: ReactPHP, Amp, Revolt, FrankenPHP, RoadRunner, multiple Swoole layers. Every option forces you to care about fibers, promises, event loops, manual cancellation, and cleanup in your application code. Average developers either stay away or get burned by leaks, blocked workers, and mysterious production crashes.

**Phalanx gives you one thing the others don't: a managed async runtime where the Scope contract does the heavy lifting. Your business logic stays simple. The dangerous parts don't leak into your code.**

### A full application, one scope at a time

```php
// HTTP route, CLI command, WebSocket handler, background agent
// All written as plain invokable classes that receive a Scope

final class GetUser implements Route
{
    public function __invoke(Scope $scope, int $id): User
    {
        return $scope->service(UserRepo::class)->find($id);
    }
}

final class AnalyzeRepo implements Command
{
    public function __invoke(ExecutionScope $scope, string $url): Report
    {
        // concurrent, race, retry, timeout, worker dispatch — all on the scope
        return $scope->concurrent(
            new CloneRepo($url),
            new RunStaticAnalysis(),
            new GenerateAiSummary()
        );
    }
}
```

No `await`. No manual `Coroutine::create()`. No thinking about the event loop. It reads like synchronous PHP.

This isn't just HTTP. The same contract powers CLI commands, WebSocket listeners, background workers, streaming agents, and (soon) native async TUIs — everything lives under one consistent, protected surface.

### The Scope system is your CYA

Every unit of work in Phalanx executes inside an owned `Scope`. This is the protective layer that makes long-running PHP safe for normal developers:

- **Task ownership & supervision** — Every job becomes a traceable `TaskRun`
- **Automatic cancellation** — Disconnects, timeouts, explicit cancels all propagate correctly
- **Guaranteed disposal** — `onDispose()` hooks always fire, even on abrupt shutdown
- **Wait diagnostics** — Know exactly why a coroutine is suspended and for how long
- **Service lifecycle** — Singletons and scoped services with clean startup/shutdown
- **Worker & boundary safety** — Closures and state never leak across process lines

You don't manage fibers or pools. You use the narrowest scope interface you need and let Aegis handle the rest.

### Phalanx packages

| Package        | What it gives you |
|----------------|-------------------|
| phalanx-aegis  | The Scope contract + task supervision, cancellation, services, concurrency primitives |
| phalanx-stoa   | HTTP server, routing, middleware, request lifecycle |
| phalanx-archon | CLI commands with the exact same Scope surface |
| phalanx-hermes | WebSocket connection & session management |
| phalanx-styx   | Backpressure streams, emitters, channels |
| phalanx-athena | AI agents, tool use, streaming, provider limits |
| phalanx-postgres | Native async Postgres + pools + transactions |
| phalanx-redis  | Async Redis client with managed pools |
| phalanx-grammata | Safe concurrent file operations |

Everything else (TUI, workers, additional protocols) is actively landing.

### Why Phalanx exists

Most developers want concurrent HTTP, realtime WebSockets, parallel workers, streaming responses, and AI agent loops in their PHP apps — without hiring a runtime specialist or debugging reference cycles at 3am.

Phalanx delivers exactly that surface: powerful under the hood, invisible at the call site, and safe by default. No more 3am reference-cycle hunts.

The internals are still evolving, but the contract is stable: **if Phalanx runs your task, Phalanx owns its lifetime**.

---

Monorepo with automatic read-only package splits.  
All development happens here → https://github.com/phalanx-php/phalanx

Work in progress. More examples and package-level documentation arriving as the 0.2 OpenSwoole foundation stabilizes.
