# ripht-dory POC

Proof of concept: running Swoole coroutines, channels, and HTTP server inside a Rust binary via ripht-php-sapi under the embed SAPI.

## Results

Levels 0-4 pass. The thesis holds: Swoole's event loop, coroutine scheduler, runtime hooks, coordination primitives, and HTTP server all work correctly when PHP is hosted via the embed SAPI.

| Level | Result | What it proves |
|-------|--------|---------------|
| 0 | PASS | Swoole 6.2.1 loads, coroutine/server classes available |
| 1 | PASS | Co::create, Co::sleep, correct interleaving order |
| 2 | PASS | Runtime hooks make usleep() coroutine-aware (100ms concurrent, not 150ms sequential) |
| 3 | PASS | Channel push/pop, WaitGroup, bounded backpressure, channel timeout |
| 4 | PASS | HTTP server in SWOOLE_BASE mode, request handling, graceful shutdown |
| 5 | SKIP | Requires OpenSwoole (Phalanx uses OpenSwoole namespaces; this POC builds with Swoole) |

## Findings

1. **SAPI name gating confirmed.** Swoole gates `Scheduler::start()` and `Runtime::enableCoroutine()` on `php_sapi_name() === 'cli'`. Fix: set ripht's SAPI name to `"cli"` (one line in `sapi/mod.rs`). Currently using a local ripht build with this change.

2. **OpenSSL 4.0 breaks SPC builds.** SPC 2.6.1 downloads OpenSSL 4.0.0 by default, which has fully opaque ASN1 types that PHP 8.4's `ext/openssl` can't compile against. Fix: pin OpenSSL 3.4.1 via `--custom-url`.

3. **Swoole vs OpenSwoole API differences.** `Co::run()` doesn't exist in Swoole -- use `Swoole\Coroutine\Scheduler`. `Server::SIMPLE_MODE` doesn't exist -- use `SWOOLE_BASE`. `\Swoole\Coroutine\run()` function doesn't exist either.

4. **No event loop or timer issues.** kqueue infrastructure works correctly under embed SAPI on macOS ARM.

5. **No bailout/crash issues.** HTTP server starts, handles multiple requests, and shuts down cleanly.

## Setup

### 1. Build libphp.a with Swoole

```bash
./scripts/build-libphp.sh
```

Uses SPC to compile a static `libphp.a` with Swoole and installs to `~/.ripht/php/`. Takes 5-10 minutes. Pins OpenSSL 3.4.1 to avoid the 4.0 incompatibility.

### 2. Build the Rust binary

```bash
cargo build
```

### 3. Run the levels

```bash
cargo run -- scripts/level0-sapi-init.php
cargo run -- scripts/level1-coroutines.php
cargo run -- scripts/level2-runtime-hooks.php
cargo run -- scripts/level3-channel-wg.php
cargo run -- scripts/level4-http-server.php
cargo run -- scripts/level5-aegis-scope.php
```

### With execution hooks (routes output through Rust)

```bash
cargo run -- scripts/level4-http-server.php --hooks
```

## What's next

Level 5 will pass once DoryBin's custom SPC registry builds OpenSwoole into libphp.a (or ripht adds a configurable SAPI name feature). The underlying primitives are proven -- the remaining work is namespace alignment between the POC's Swoole build and Phalanx's OpenSwoole expectations.

See vault note: `50-projects/phalanx/context/dory-build-spc-and-ripht-2026-05-27.md`
