# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - Unreleased

### Breaking Changes

- **`resolve(callable)` replaced with `execute(Dispatchable)`**
  - All task execution now requires a `Dispatchable` type
  - Use `new Task(static fn(Scope $s) => ...)` for ad-hoc tasks
  - Use invokable classes implementing `Dispatchable` for reusable tasks

- **All concurrent methods now accept `Dispatchable[]` instead of `callable[]`**
  - `concurrent()`, `race()`, `any()`, `settle()`, `series()`, `waterfall()`
  - `map()` closure must return `Dispatchable`

- **`TaskInterceptor::process()` signature changed**
  - Now receives `Dispatchable` instead of `object`
  - Guaranteed to receive typed task objects

- **`resolveFresh(callable)` replaced with `executeFresh(Dispatchable)`**

- **`timeout(float, callable)` replaced with `timeout(float, Dispatchable)`**

- **`retry(callable, RetryPolicy)` replaced with `retry(Dispatchable, RetryPolicy)`**

- **Static closure enforcement in `Task`**
  - Non-static closures throw `InvalidArgumentException` at construction
  - Prevents reference cycles that cause memory leaks
  - Use `static fn(Scope $s) => ...` or explicit capture via `use()`

### New Features

- **`CommandScope` scope decorator** - Typed CLI scope with `$cs->args` and `$cs->options`
  - `ArgvParser` - Full POSIX-style option/argument parsing
  - `CommandArgs` value object - positional argument access with `required()`, `optional()`, `all()`
  - `CommandOptions` value object - option access with `get()`, `flag()`, `has()`
  - `HelpGenerator` - Per-command help output from `CommandConfig` definitions
  - `CommandValidator` interface - Custom validation before handler execution

- **`HttpScope` scope decorator** - Typed HTTP scope with `$hs->params` and `$hs->query`
  - `RouteParams` value object - route parameter access
  - `QueryParams` value object - query string parameter access

- **`ExecutionScopeDelegate` trait** - Reduces boilerplate for scope decorators

- **Docker CLI example app** - Demonstrates `CommandScope` with typed args/options

- **`Dispatchable` interface** - Core task contract
  - `__invoke(Scope): mixed`
  - All tasks now have identity and introspection capabilities

- **Behavior interfaces with PHP 8.4 property hooks**
  - `Retryable` - automatic retry policy via `$retryPolicy { get; }`
  - `HasTimeout` - automatic timeout via `$timeout { get; }`
  - `UsesPool` - pool-aware scheduling via `$pool { get; }`
  - `HasPriority` - priority queue scheduling via `$priority { get; }`
  - `Traceable` - custom trace names via `$traceName { get; }`

- **`Task` class** - Static closure wrapper
  - Constructor validates static closure requirement
  - Immutable `TaskConfig` for declarative behavior
  - `with()` and `withConfig()` for configuration

- **`TaskConfig`** - Immutable configuration
  - `name`, `priority`, `pool`, `retry`, `timeout`, `concurrencyLimit`, `trace`, `tags`
  - `with()` for partial updates

- **`Pool` enum** - Standard pool types
  - `Http`, `Database`, `Redis`, `FileSystem`, `Queue`

- **`TaskScheduler`** - Priority + pool-aware batch execution
  - Respects `HasPriority` for ordering
  - Respects `UsesPool` for concurrency limits per pool
  - WeakMap-based result tracking

- **`LazySequence`** - Generator-based streaming
  - `from(Closure)`, `of(iterable)` constructors
  - `map()`, `filter()`, `take()`, `chunk()` transforms
  - `mapConcurrent()` for bounded concurrent processing
  - Terminal operations: `collect()`, `reduce()`, `first()`

- **`Transform`** - Input + transformer composition
  - Wraps value with transformation function

- **`ManagedResource`** - WeakMap-based resource cleanup
  - `wrap(object, Closure)` for automatic cleanup on GC
  - `flushOnShutdown()` for cleanup registration

- **Scope attributes**
  - `withAttribute(string, mixed): Scope` - creates child scope with attribute
  - `attribute(string, mixed): mixed` - reads attribute with default
  - Waterfall uses `_waterfall_previous` attribute

- **`defer(Dispatchable)`** - Fire-and-forget task execution
  - Errors logged but not propagated

- **`HttpRunner`** - ReactPHP HTTP server
  - Per-request scopes with timeout
  - Request available via `request` attribute

- **`ConsoleRunner`** - CLI command dispatch
  - Command registration via `command(string, Dispatchable)`
  - Args available via `args` attribute

### Changed

- **Behavior pipeline order**: `timeout wraps retry wraps trace wraps work`
  - Behaviors resolved from `TaskConfig` or interfaces automatically
  - No manual wrapping needed when using interfaces

- **`DeferredScope` updated** - All methods now use `Dispatchable` types

### Infrastructure (Unchanged from v1)

- `Concurrency/` - CancellationToken, RetryPolicy, Settlement, SettlementBag
- `Exception/` - All exception classes
- `Lifecycle/` - LifecycleCallbacks, LifecyclePhase
- `Service/` - Complete DI system
- `Support/` - ClassNames utility
- `Trace/` - Trace, TraceEntry, TraceType
- `Middleware/` - ConditionalTransform, ServiceTransform, TagBasedTransform

### Migration Guide

1. **Replace closures with Task wrapper**
   ```php
   // Before
   $scope->resolve(fn($s) => $s->service(Db::class)->query());

   // After
   $scope->execute(new Task(static fn(Scope $s) => $s->service(Db::class)->query()));
   ```

2. **Replace retry/timeout wrappers with interfaces**
   ```php
   // Before
   $scope->retry(fn($s) => $work, RetryPolicy::exponential(3));

   // After
   final class MyWork implements Dispatchable, Retryable {
       public RetryPolicy $retryPolicy { get => RetryPolicy::exponential(3); }
       public function __invoke(Scope $scope): mixed { /* work */ }
   }
   $scope->execute(new MyWork());
   ```

3. **Update concurrent method calls**
   ```php
   // Before
   $scope->concurrent([
       fn($s) => fetchA($s),
       fn($s) => fetchB($s),
   ]);

   // After
   $scope->concurrent([
       new Task(static fn(Scope $s) => fetchA($s)),
       new Task(static fn(Scope $s) => fetchB($s)),
   ]);
   ```

4. **Ensure closures are static**
   ```php
   // Invalid - will throw
   new Task(fn($s) => $this->doWork());

   // Valid - explicit capture
   $self = $this;
   new Task(static fn(Scope $s) => $self->doWork());
   ```
