<p align="center">
  <img src="logo.svg" alt="Phalanx" width="520">
</p>

# Phalanx

**Async PHP that feels like normal code — protected by a runtime that actually has your back.**

Managed concurrency, surfaced as plain PHP. Underneath, a small kernel tracks every resource in a ledger and nests scopes inside scopes — Russian-doll style — so cleanup and control propagate through every layer.

If this resonates, **a star helps us gauge whether to keep pushing**. We're early; signal matters.

### A working HTTP app, top to bottom

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload_runtime.php';

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\Stoa;
use Phalanx\Task\Scopeable;

class UserRepo
{
    private array $users = [
        1 => ['id' => 1, 'name' => 'Ada Lovelace'],
        2 => ['id' => 2, 'name' => 'Alan Turing'],
    ];

    public function find(int $id): ?array
    {
        return $this->users[$id] ?? null;
    }
}

class AppBundle extends ServiceBundle
{
    public function services(Services $services, AppContext $context): void
    {
        $services->singleton(UserRepo::class)->factory(static fn() => new UserRepo());
    }
}

class ShowUser implements Scopeable
{
    public function __invoke(RequestScope $scope): array
    {
        $id = (int) $scope->params->get('id');
        $user = $scope->service(UserRepo::class)->find($id);

        return ['user' => $user];
    }
}

return static function (array $context): \Closure {
    $app = Stoa::starting($context)
        ->bundles(new AppBundle())
        ->routes(RouteGroup::of([
            'GET /users/{id:int}' => ShowUser::class,
        ]))
        ->build();

    return static fn (): int => $app->run();
};
```

That's the whole thing — entry, controllers, routing, a service lookup. The handlers are plain invokable classes. The `RequestScope` they receive is the protected execution context: it knows the route params, the request, the active services, and what to clean up. No `await`. No fiber bookkeeping. It reads like synchronous PHP.

The same contract powers CLI commands, WebSocket handlers, background workers, and streaming agents — one consistent surface across transports.

### The CYA system

Every unit of work in Phalanx executes inside an owned `Scope`. This is the protective layer that makes long-running PHP safe for normal developers:

- **Task ownership & supervision** — Every job becomes a traceable `TaskRun`
- **Automatic cancellation** — Disconnects, timeouts, explicit cancels all propagate correctly
- **Guaranteed disposal** — `onDispose()` hooks always fire, even on abrupt shutdown
- **Wait diagnostics** — Know exactly why a coroutine is suspended and for how long
- **Service lifecycle** — Singletons and scoped services with clean startup/shutdown
- **Worker & boundary safety** — Closures and state never leak across process lines

You don't manage fibers or pools. You use the narrowest scope interface you need and let Aegis handle the rest.

### Built on OpenSwoole 26

Phalanx runs on OpenSwoole 26 — native PHP fibers, `io_uring`, `Channel`, `WaitGroup`, `ClientPool`. Early results are very promising: the runtime kernel benchmarks are healthy, the boot harness catches misconfiguration before workers spin up, and the test surface is stabilizing fast. If you have an OpenSwoole environment lying around, **try it out** — feedback on real workloads is exactly what we need right now.

### Demos

Real, runnable examples covering the core surface. Each ships with a `.env.example` and runs via `php demo.php`:

- [Aegis kernel](demos/aegis) — runtime policy, scope supervision, cancellation, singleflight, runtime memory
- [Stoa HTTP](demos/stoa) — basic routing, JSON API, realtime SSE, runtime lifecycle
- [Archon CLI](demos/archon) — basic commands, interactive input, supervised concurrency, runtime lifecycle
- [Athena AI](demos/athena) — concurrent streaming across providers, Guzzle SDK coexistence
- [Surreal](demos/surreal) — in-memory RPC, live queries

---

Monorepo with automatic read-only package splits — all development happens here.
