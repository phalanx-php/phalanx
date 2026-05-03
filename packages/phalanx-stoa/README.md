<p align="center">
  <img src="brand/logo.svg" alt="Phalanx" width="520">
</p>

# Phalanx Stoa

> Part of the [Phalanx](https://github.com/phalanx-php/phalanx-aegis) async PHP framework.

Stoa is the OpenSwoole-native HTTP runtime for Phalanx. It translates OpenSwoole requests into PSR-7 requests, dispatches FastRoute-backed handlers inside Aegis-owned request scopes, writes PSR-7 responses back to OpenSwoole, and records request lifecycle through Aegis managed resources.

Current supported surface:

- HTTP routing, middleware, input hydration, validation, and auth scopes.
- Request timeout, disconnect cancellation, scope disposal, and worker-local drain cleanup.
- Typed Stoa runtime IDs for request resources, annotations, and diagnostic events.
- Symfony Runtime integration through `Phalanx\Stoa\Stoa::starting($context)`.

Deferred until the Aegis long-lived resource gate reopens:

- Native SSE resources.
- Native WebSocket/SocketScope replacement.
- Native UDP lifecycle handling.
- Response leases, outbound/client behavior, and global active-resource truth APIs.

## Commands

Run from the monorepo root:

```bash
composer demo:stoa
composer bench:stoa -- --format=json
php vendor/bin/phpunit packages/phalanx-stoa/tests --display-phpunit-notices
```

---

Stoa owns HTTP meaning. Aegis owns canonical runtime lifecycle mechanics.
