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

- [Aegis kernel](packages/phalanx-aegis/examples) — runtime policy, scope supervision, cancellation, singleflight, runtime memory
- [Stoa HTTP](packages/phalanx-stoa/examples) — basic routing, JSON API, realtime SSE, runtime lifecycle
- [Archon CLI](packages/phalanx-archon/examples) — basic commands, interactive input, supervised concurrency, runtime lifecycle
- [Athena AI](packages/phalanx-athena/examples) — concurrent streaming across providers, Guzzle SDK coexistence
- [Surreal](packages/phalanx-surreal/examples) — in-memory RPC, live queries

### Pharos — the proving ground

<details>
<summary><strong>The next big bet: replace nginx in front of your PHP app with PHP itself.</strong></summary>

Let's be straight up front. Will Pharos out-RPS a tuned nginx serving static files? No. nginx is C, decades of tuning, and it doesn't have to think. Will Pharos handle the traffic the average PHP app actually sees — HTTPS termination, vhost routing, upstream proxying, WebSockets, automatic Let's Encrypt cert renewal — at speeds that disappear into the noise of your handler? Absolutely. The bottleneck in a real app is almost never the proxy hop.

What Pharos gives up in raw throughput it earns back by knowing what's running behind it. Today the typical PHP deploy is four moving parts that don't talk to each other: nginx out front, certbot on a cron renewing your HTTPS certificates, PHP-FPM running the app, supervisord keeping it all alive. Four configs, two daemons, a cron, and a front door that has no idea what your app is doing. When a client hangs up, the upstream PHP request keeps grinding. When a pool starves, you find out from a 504 in a log file. When you want to roll a route, you reload nginx and hope.

Pharos collapses all of that into one Phalanx process running alongside your app — same kernel, same scopes, same trace stream. Every proxied connection is a supervised `TaskRun`. Client disconnect cancels the upstream request mid-flight. Pool leases, circuit state, and per-vhost rate limits show up in the same trace as your handler. Config is a PHP value tree — hot-reload swaps it atomically without recycling workers. Cert renewals (the certbot job) run as supervised tasks inside the same process, not as a separate cron.

The OpenSwoole pieces it actually uses:

- **TLS termination** via `ssl_cert_file` / `ssl_key_file` and the `sslSniCerts` map for multi-vhost SNI — set at `Server::set()` time
- **Upstream pooling** through `ManagedPool` over `OpenSwoole\ConnectionPool`, with leases held for the life of the proxied request
- **Per-worker shared state** for circuit breakers and rate limit buckets via `OpenSwoole\Atomic` (cross-worker via `OpenSwoole\Table` lands later)
- **Graceful drain** via `Server::reload()` with a bounded `max_wait_time` — the same path used for cert hot-swap
- **HTTP/2 upstream** via `open_http2_protocol` once the Iris keep-alive work lands

And the things it deliberately doesn't do, either because OpenSwoole doesn't expose the hooks for it or because it isn't the job we're signing up for:

- **No HTTP/3 or QUIC** — not in stable OpenSwoole 26.2; Swoole Lab fork only
- **Cert renewal is HTTP-01 only** — Let's Encrypt offers a second renewal flow (TLS-ALPN-01) that needs hooks into the raw TLS handshake; OpenSwoole doesn't expose those, so Pharos sticks to the HTTP-01 flow (port 80 has to be reachable)
- **No OCSP stapling** — not in OpenSwoole's TLS config surface
- **No inbound mTLS yet** — client cert verification on inbound isn't wired through `Server::set()` yet (upstream mTLS works)
- **Not a static CDN** — `sendfile(2)` is bypassed under TLS; serve assets from a real CDN in front
- **No L4 proxy, no full WAF, no service mesh control plane** — Pharos is an HTTP/WS edge for Phalanx apps, not a general proxy

```php
return static function (array $context): \Closure {
    $app = Pharos::starting($context)
        ->upstreams([
            'app' => UpstreamPool::of('http://127.0.0.1:8080', weight: 10),
        ])
        ->routes(RouteGroup::of([
            'GET /healthz'  => Healthz::class,
            '* /api/{...}'  => ProxyTo::pool('app'),
        ]))
        ->build();

    return static fn (): int => $app->run();
};
```

Pharos is also the proving ground of the Phalanx promise — if a managed-runtime PHP framework can replace the proxy in front of itself, the rest is detail.

</details>

---

Monorepo with automatic read-only package splits — all development happens here.

Work in progress. The 0.2 OpenSwoole foundation is stabilizing; more examples and package documentation arriving as the surface settles.
