<p align="center">
  <img src="brand/logo.svg" alt="Phalanx" width="520">
</p>

# Phalanx Eidolon

> Part of the [Phalanx](https://github.com/phalanx-php/phalanx-aegis) async PHP framework.

Write a PHP handler. Get a TypeScript hook. The `__invoke` signature on your handler class IS the API contract -- the framework reflects on it to generate an OpenAPI spec, and Kubb transforms that spec into typed React Query hooks, Zod schemas, and query keys. One source of truth, full-stack type safety, zero manual API client code.

Phalanx's async nature enables patterns that request-response PHP frameworks can't offer: signals that flow continuously over WebSocket and SSE, concurrent data loading that streams to the page as each fetch resolves, and multi-agent AI output rendered in real time. The server doesn't just respond to requests -- it drives the frontend.

**This package is in early development.** The route contract system, input hydration, and OpenAPI generation are implemented in `phalanx/stoa`. The signal system, Kubb integration, and TypeScript client are next.

---

Phalanx is getting a facelift, and not an insignificant one. The fun is just getting started.
