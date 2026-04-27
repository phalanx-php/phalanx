<p align="center">
  <img src="brand/logo.svg" alt="Phalanx" width="520">
</p>

# Phalanx CDP

> Part of the [Phalanx](https://github.com/phalanx-php/phalanx-aegis) async PHP framework.

Chrome DevTools Protocol client for Phalanx. Async-native browser automation built on `phalanx/ws-client` for WebSocket transport and `phalanx/stream` for event handling.

**This package is not yet implemented.** The design is specified in [SPEC.md](SPEC.md) -- source code will follow.

## Planned Features

- CDP session management with automatic domain ref-counting
- JSON-RPC message routing over WebSocket (concurrent RPC responses and event streams on a single connection)
- Browser/tab discovery via HTTP endpoints (`/json/version`, `/json/list`)
- Phalanx task layer: `Navigate`, `Evaluate`, `Screenshot`, `Click`, `Type`, `WaitForSelector`, `WaitForNavigation`, `InterceptRequests`, `FillForm`
- Event streams as `Emitter` pipelines (console messages, network events, page lifecycle)
- Scope-driven cancellation and timeout for all operations

## Dependencies

| Package | Purpose |
|---------|---------|
| `phalanx/aegis` | Task execution, scope system, cancellation |
| `phalanx/styx` | Event stream composition via `Emitter` |
| `phalanx/hermes` | WebSocket client transport (RFC 6455, client-mode framing) |
| `react/http` | HTTP `Browser` for CDP discovery endpoints |

## Architecture

The spec defines three layers: `CdpConnection` (WebSocket transport), `CdpSession` (RPC routing and event dispatch), and a task layer of `Scopeable`/`Executable` classes that compose CDP operations through the standard Phalanx execution model.

See [SPEC.md](SPEC.md) for the full design, message flow diagrams, and API surface.
