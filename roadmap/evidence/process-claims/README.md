# Process & Sidecar Claims — Empirical Bench

This directory exists to validate (or refute) eleven claims about OpenSwoole 26
process orchestration, Symfony Process under runtime hooks, channel-based
backpressure, and Styx redesign. The original claim-capture note lives in
private project context; this directory keeps the runnable public evidence.

The bench lives in `poc/` because it enables `Runtime::enableCoroutine()` for
testing — Aegis owns hook policy in framework code. These scripts are
validation experiments, not framework consumers.

## Environment

- PHP 8.4.16 (Homebrew)
- OpenSwoole 26.2.0
- Symfony Process ^7.2
- macOS Darwin 25.3.0 (Apple Silicon)

## How to run

    composer install
    php tests/01-openswoole-process-in-coroutine.php
    php tests/02-proc-open-in-coroutine.php
    php tests/03-symfony-process-hooked.php
    php tests/04-co-system-exec.php
    php tests/05-channel-backpressure.php
    php tests/06-cancellation-orphans.php
    php tests/07-fd-pressure-semaphore.php
    php tests/08-sidecar-pool.php

Each test writes a stamped result file to `results/`.

## Result summary

| # | Claim | Status | Evidence |
|---|---|---|---|
| 1 | `OpenSwoole\Process` unsafe inside a coroutine | PROVEN | C-level fatal `unable to create OpenSwoole\Process with async-io threads` during `__construct` |
| 2 | `proc_open` is safe in a coroutine | PROVEN | 60-tick 25ms ticker, max gap 27ms, total 1513ms vs 1500ms baseline; two 530ms procs ran concurrent |
| 3 | Symfony Process becomes non-blocking under hooks | **PROVEN** | All three patterns (iterator / `run()` / `wait(callback)`) yield. Sibling ticker max gap 26.5ms vs 25ms baseline. **This is the headline result.** |
| 4 | `Co\System::exec` is async for one-shot commands | PROVEN | 8 sequential = 1771ms; 8 parallel = 219ms. 8x speedup |
| 5 | Bounded `Channel` propagates backpressure to the OS pipe | PROVEN | Child took 2137ms to write 200 lines while consumer ran at 10ms/line |
| 6 | Shell-wrapped commands risk orphan grandchildren | PROVEN | Variant A (`sleep 30 & wait`) leaked grandchild pid; argv form and `exec` prefix safe |
| 7 | Concurrent `proc_open` needs FD limits | PROVEN | Unlimited: 187 FD peak; semaphore=8: 87 FD peak |
| 8 | Prebooted sidecar pool over Unix socket | PATTERN PROVEN | Symfony-Process-spawned sidecar + `Co\Client` works; pool needed for parallelism |

Two further claims from the original conversation were architectural and
covered by these mechanism tests rather than separate scripts:

- **Channels with bounded inbox per subscriber for Styx** — the building
  blocks are validated by Test 5. A separate Styx redesign sketch belongs in
  the framework, not the bench.
- **Aegis owns lifecycle/cancellation** — already true in code; not a claim
  to validate, a constraint to honor in any adapter.

## Cross-walk vs current Phalanx code

`phalanx-aegis/src/System/StreamingProcess*.php` is **866 lines** of custom
`proc_open` plumbing (open + line-buffered read/write + timeout + SIGTERM→SIGKILL
escalation + Aegis resource registration + scope dispose).

Symfony Process gives us all of the substrate behavior for free:

- argv + shell-cmd forms with proper escaping
- TTY/PTY support
- idle vs hard timeouts
- incremental output (`getIncrementalOutput`, `getIterator`)
- exit/signal accounting, `stop()` with grace + force escalation
- per-platform signal mapping
- 15+ years of battle-testing

What StreamingProcess uniquely contributes is the Aegis adapter layer:
resource registration, annotations, events, scope-on-dispose cleanup,
WaitReason tagging.

**Recommendation**: replace StreamingProcess internals with a thin Symfony
Process adapter. Aegis surface (`Phalanx\System\StreamingProcess` /
`StreamingProcessHandle`) stays — the 866 lines of pipe and signal plumbing
collapse to ~150-200 lines of adapter code. We keep the Aegis contract,
shed the maintenance burden.

## What this bench did not cover

- **Windows.** All tests are POSIX-only. `proc_terminate` semantics and pipe
  behavior differ on Windows; Symfony Process abstracts most of that.
- **Long-lived sidecar supervision** (restart on crash). The pattern is
  proven; supervision is a framework concern.
- **Hooks-off paths.** Phalanx pin enables hooks; the bench reflects that.
  If a downstream package needs hooks-off behavior, retest there.
- **PSR-7 SSE bridging into channels.** Out of scope here; covered by Stoa
  deferred work.
