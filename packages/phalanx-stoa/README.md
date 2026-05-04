<p align="center">
  <img src="brand/logo.svg" alt="Phalanx" width="520">
</p>

# Phalanx Stoa

> Part of the [Phalanx](https://github.com/phalanx-php/phalanx-aegis) async PHP framework.

Stoa is the OpenSwoole-native HTTP runtime for Phalanx. It translates OpenSwoole requests into PSR-7 requests, dispatches FastRoute-backed handlers inside Aegis-owned request scopes, writes PSR-7 responses back to OpenSwoole, and records request lifecycle through Aegis managed resources.

Current supported surface:

- HTTP routing, middleware, input hydration, validation, and auth scopes.
- Request timeout, disconnect cancellation, scope disposal, and worker-local drain cleanup.
- Chunked PSR-7 response writing modeled on `OpenSwoole\Core\Psr\Response::emit()` (100K chunks) with HEAD/204/304 normalization preserved.
- Native static-asset offload via `enable_static_handler` + `document_root`.
- HTTP compression (`http_compression`) on by default.
- Server-wide active-request queries via Aegis `ServerStats` (`activeRequests(RegistryScope::Server)` reads `$server->stats()['connection_num']`).
- Response delivery leases (`stoa.response` domain) wired through `OpenSwoole\Server::onBufferEmpty($fd)` for delivery-promise tracking.
- Native SSE streams via `Phalanx\Stoa\Sse\SseStream` + `SseStreamFactory` + `SseEncoder`.
- HTTP upgrade seam via `Phalanx\Stoa\Http\Upgrade\HttpUpgradeable` + `UpgradeRegistry`; Hermes plugs in WebSocket upgrades.
- Native UDP listener via `Phalanx\Stoa\Udp\UdpListener` + `UdpSession` + `UdpPacketHandler`.
- Outbound HTTP/1.1 client via `Phalanx\Stoa\Http\Client\StoaHttpClient` over Aegis `TcpClient` + `DnsResolver`. HTTPS supported through Aegis `TlsOptions` (secure-by-default `verifyPeer: true`, automatic `hostName` from URL). Bounded TTL connection pool (`HttpConnectionPool`), idempotent-only retry policy (`RetryPolicy`), redirect handling (`RedirectPolicy`), and streaming response view (`StreamingHttpResponse`).
- Symfony Runtime integration through `Phalanx\Stoa\Stoa::starting($context)`.

## Commands

Run from the monorepo root:

```bash
composer demo:stoa
composer bench:stoa -- --format=json
php vendor/bin/phpunit packages/phalanx-stoa/tests --display-phpunit-notices
```

---

Stoa owns HTTP meaning. Aegis owns canonical runtime lifecycle mechanics.
