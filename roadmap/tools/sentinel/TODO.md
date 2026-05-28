# TODO

## Done (2026-03-29)

- [x] Singleflight primitive (`$scope->singleflight($key, $task)`) in phalanx-aegis
- [x] Pre-fetch changed file contents into review prompts
- [x] Non-blocking daemon8 bridge (ReactPHP Browser replaces blocking curl)
- [x] `+>` prompt, token tracking, memory stats in status line
- [x] Tempest syntax highlighting for code blocks (`tempest/highlight`)
- [x] Step separator (thinking vs response)
- [x] Feedback loop fix (no broadcast from humanMessage, externals logged only)
- [x] Realpath fix for project root
- [x] Meta-prompt: agents use tools proactively

## Immediate: Smart Pipeline

The next major piece. Full ADR lives in private project context.

### Pipeline Primitive in phalanx-athena
- `Pipeline` class: prepare → concurrent agents → converge
- Emitter-based interface (yields `PipelineEvent` per stage)
- Prepare and converge are `AgentDefinition` with `Retryable`
- Fallback: exponential backoff → raw output if pipeline fails
- Structured output from converge via `Turn::outputClass()`

### Rewrite Coordinator as Pipeline Consumer
- Coordinator creates `Pipeline`, submits input, renders result
- Handles sentinel-specific concerns (daemon8 broadcast, file watching) outside pipeline
- Remove current prompt building, result collection, formatting logic

### Bug Fixes
- Fix daemon8 session filter (own broadcasts read back as external)
- Fix Daemon8CoordinationTest (Daemon.php SDK path)

### Orchestration Pipeline: Prepare → Agents → Converge

A fast model (Haiku) as an orchestration layer on both sides of domain agents:

**Prepare stage** (before agents):
- Receives raw input (human message or file changes) + agent roster with domain descriptions
- Reads files once, decides what context each agent actually needs
- Produces optimized per-agent prompt packages instead of giving everyone the same blob
- Eliminates agents needing to call ReadFile for content the prepare stage already has

**Converge stage** (after agents):
- Receives: original input + all agent responses + rendering config
- Deduplicates code blocks: renders shared code once as a numbered reference block
- Rewrites agent commentary to use line references instead of re-quoting code
- Strips preamble ("I'll review...", "Let me examine..."), keeps only findings
- Omits agents that said "No issues" unless all did (then summarize as clean)
- Preserves severity labels, applies severity-first ordering
- Outputs terminal-ready text (with ```lang blocks for syntax highlighting)

**Config (sentinel.yaml or SentinelConfig)**:
- `pipeline.prepare: true|false` — toggle prepare stage
- `pipeline.converge: true|false` — toggle converge stage
- `pipeline.model: claude-haiku-4-5` — model for orchestration (fast + cheap)
- `render.syntax_highlight: true|false` — toggle tempest highlighting
- Running without pipeline = current behavior (raw agent output)

**Implementation notes**:
- Both stages use the same `AgentLoop` infrastructure, just different system prompts
- Prepare and converge are `AgentDefinition` implementations with `provider()` returning Haiku
- No tools needed for either stage — pure text in, text out
- Coordinator becomes thinner: dispatches to pipeline stages, doesn't micromanage formatting
- Cost: ~$0.002 per review round (two Haiku calls, ~500-1000 tokens each)
- Latency: ~200-400ms per stage (Haiku is fast)

---

## TUI Integration (Paused)

**Status**: `phalanx/theatron` library complete (62 source files, 105 tests). TUI command scaffolded and partially working. Paused to focus on raw sentinel CLI correctness first.

### What Works
- Alternate screen mode with bordered agent panels in dynamic grid layout
- Status bar, input line with Box border, space key, all navigation keys
- Agent registration renders in panels
- File change notifications display in panels
- Keyboard input accepted and submitted on Enter
- Clean terminal restoration on exit (Ctrl+C, `quit`, crash)

### What Doesn't Work Yet

**Agent responses don't come back from TUI input** (Critical)
- Root cause: `\React\Async\async()` creates a standalone fiber detached from Phalanx's `ExecutionScope` fiber tree
- When `coordinator->humanMessage()` calls `$scope->concurrent($tasks)` inside that detached fiber, the scope may not support it
- The raw sentinel works because StdinReader runs inside a Task that's part of `$scope->concurrent()`, so the fiber is in the scope tree
- Fix options: (a) `$scope->execute(Task::of(...))` for properly-tracked child fiber, (b) investigate `ExecutionLifecycleScope` re-entrant concurrent support
- Detailed analysis in `phalanx/packages/phalanx-theatron/TODO.md`

**Real-time token streaming not implemented**
- Coordinator collects all tokens then renders complete text
- For TUI, tokens should stream character-by-character into ScrollableText panels
- Need token callback in `executeAndCollect()` that dispatches to Surface
- ConsoleRenderer already has `agentStreamStart/agentToken/agentStreamEnd` — just not wired

### Files
- `src/Sentinel/SentinelTuiCommand.php` — TUI command (Executable)
- `src/Sentinel/Render/TuiRenderer.php` — Surface-backed ReviewRenderer
- `src/Sentinel/Render/ReviewRenderer.php` — interface (both renderers implement)
- `bin/commands/sentinel-tui.php` — command definition
- `phalanx/packages/phalanx-theatron/` — the library (62 files)
- `phalanx/packages/phalanx-theatron/TODO.md` — library-specific TODO

### Architecture Context
- Full render pipeline, input flow, and anti-deadlock patterns are captured in private project context
- STDIN ownership constraint: raw sentinel (cooked mode) and TUI (raw mode) CANNOT share STDIN — separate commands, not a flag

---

## Agent Parallelism (Future)

Currently agents run concurrently via fibers in a single process. For CPU-heavy or latency-sensitive workloads, spawn each agent in its own child process.

### Approach
- Use `react/child-process` + `clue/ndjson-react` for IPC (same pattern as `phalanx-hydra`)
- Each agent process runs `AgentLoop::run()` independently
- Parent process collects results via NDJSON stream
- `wyrihaximus/react-child-process-pool` for crash recovery and round-robin dispatch

### What Needs to Happen
1. Make `ReviewAgent` serializable (it already uses a `Dossier` with string fields)
2. Create a worker script that receives agent config + prompt via stdin, runs the loop, writes result to stdout
3. Coordinator dispatches to worker pool instead of `scope->concurrent()`
4. daemon8 bridge messages flow through parent process (workers don't need direct daemon access)

### Benefits
- True parallelism (multiple CPU cores for multiple LLM API calls)
- Process isolation (one agent crash doesn't kill the session)
- Memory isolation (each agent's conversation history in its own process)

### ReactPHP Packages Needed
- `clue/ndjson-react` — NDJSON framing for IPC
- `wyrihaximus/react-child-process-pool` — production worker pool
- `react/child-process` — already a dependency

---

## Async daemon8 SDK (Future)

The daemon8 PHP SDK is entirely blocking — all HTTP methods use `curl`/`file_get_contents` with 2-5s timeouts. The sentinel bridge bypasses the SDK for reads (uses ReactPHP `Browser` directly).

### Current State

| SDK Method | Transport | Blocking? |
|-----------|-----------|-----------|
| `sendUdp()` | UDP socket | No (fire-and-forget) |
| `observe()` | HTTP GET (curl) | Yes, 5s timeout |
| `send/log/warn/error` | HTTP POST (curl) | Yes, 2s timeout |

### Decision
Deferred. Bridge workaround (bypassing SDK for reads) works today. Full async SDK makes sense when other async consumers emerge or SSE/WebSocket observation is needed.

---

## Phalanx CLI Scaffolder (Future)

A `phalanx new` command that scaffolds new projects with:
- `symfony/runtime` + `autoload_runtime.php` entry point
- `.env` template with common config keys
- `ServiceBundle` skeleton
- Choice of project type: HTTP server, CLI tool, WebSocket server, worker
- PHPStan config with `wyrihaximus/phpstan-react`
- Rector config for PHP 8.4+
