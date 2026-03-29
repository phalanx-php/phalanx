# phalanx/terminal — Known Work

## Critical Bugs

### Agent messages not rendering from TUI input
**Status**: Not working
**Symptom**: Typing a message and pressing Enter in the TUI shows "YOU" in panels but no agent response comes back. No error in `/tmp/sentinel-tui-error.log`.
**Root cause**: The `async()` fiber wrapping `coordinator->humanMessage()` creates a standalone fiber detached from the Phalanx `ExecutionScope` fiber tree. When `humanMessage()` calls `$scope->concurrent($tasks)`, the scope may not support concurrent operations from a fiber it didn't create. The `$scope` reference passed into the `async()` closure is the parent command scope that's already suspended inside its own `$scope->concurrent($tasks)` call.
**Fix needed**: Either (a) use `$scope->execute(Task::of(...))` which creates a properly-tracked child fiber, or (b) create a child scope via `$scope->fork()` or equivalent, or (c) investigate how `ExecutionLifecycleScope` handles re-entrant `concurrent()` calls from external fibers. This is a Phalanx core integration question — the TUI's `async()` pattern is correct for event loop callbacks but may not compose with Phalanx's scope tree.
**Workaround**: The raw `sentinel` command works because its StdinReader runs inside a Task that's part of the `concurrent()` call, so `coordinator->humanMessage()` executes within the scope's fiber tree.

### Buffer sync approach may miss edge cases
**Status**: Fixed but needs monitoring
**Symptom**: Previously caused full-screen flicker every frame.
**Fix applied**: Changed from `swap()` to applying diff updates to the previous buffer. The swap approach corrupted the current buffer (lost non-dirty region content from 2 frames ago). Current approach syncs only changed cells.
**Risk**: If a widget renders differently on consecutive frames despite no state change (e.g., animation, time-based), cells that change then change back won't be properly tracked. Low risk for current widgets.

## Enhancement: SttyRawMode
**Priority**: Low
**What**: Use `readline_callback_handler_install()` as primary strategy (no subprocess), falling back to `stty` if ext-readline unavailable. Pattern from clue/stdio-react.
**Why**: Eliminates `proc_open('stty')` subprocess on start/stop. Faster raw mode toggle.
**Also**: Add 3-tier TTY detection (stream_isatty → posix_isatty → stat check) for better Docker/container compatibility.

## Enhancement: Real-time Token Streaming
**Priority**: Medium
**What**: Currently, the Coordinator collects ALL tokens via `executeAndCollect()` then calls `renderer->agentFeedback()` with complete text. Agents appear to respond in bursts after a delay.
**Goal**: Stream tokens character-by-character into ScrollableText panels as they arrive from the LLM API.
**Approach**: Add a token callback to `executeAndCollect()` that dispatches `AgentTokenMessage` to the Surface. The TUI renderer's `agentFeedback()` becomes a no-op (or summary), with real-time rendering handled by the token callback. The raw sentinel already has `agentStreamStart/agentToken/agentStreamEnd` methods on ConsoleRenderer — these just aren't wired up in the Coordinator.
**Depends on**: The agent response bug above being fixed first.

## Enhancement: Scrollable Agent Panels
**Priority**: Medium
**What**: Agent panels use `ScrollableText` which supports scroll, but there's no keyboard binding to scroll individual panels. Need focus management — arrow keys or tab to select which panel is "active", then PageUp/PageDown to scroll that panel.
**Approach**: Track `activePanel: int` state. Tab cycles panels. PageUp/PageDown call `scrollUp/scrollDown` on the active panel's ScrollableText. Visual indicator (brighter border?) on the focused panel.

## Enhancement: TextArea Widget
**Priority**: Low
**What**: Multi-line text input (like the InputLine but with height > 1). Currently the input box is 3 rows but the InputLine widget only renders on one line.
**Approach**: The TextArea widget exists in the plan but wasn't implemented. Would replace InputLine for multi-line prompts.

## Phalanx Core Integration Notes

### Singleflight Primitive
**Status**: Planned, not yet implemented
**What**: `$scope->singleflight($key, $task)` — deduplicates concurrent tool calls across agents. When 4 agents all request `ReadFile("src/AgentLoop.php")`, only one execution happens and all share the result.
**Vault docs**: `20-knowledge/patterns/singleflight-multi-agent-tool-dedup.md`
**Impact on TUI**: None directly. Agents will respond faster because tool calls aren't duplicated 4x. Transparent benefit.

### Strongest Link Pattern
**Status**: Proposed, future
**What**: Cross-agent result enrichment during concurrent execution. First agent to complete a tool operation produces enriched analysis that subsequent agents can leverage.
**Vault docs**: `20-knowledge/patterns/singleflight-strongest-link-pattern.md`

### Non-blocking DaemonAI SDK
**Status**: Planned
**What**: Current DaemonAI SDK uses blocking HTTP (`file_get_contents`). Replace with ReactPHP Browser for non-blocking health checks and observation polling.
**Impact on TUI**: Would eliminate the blocking `tryConnect()` call at startup.

## Architecture Notes for Future Agents

### How the Render Pipeline Works
```
State change → Widget state mutated → Region.invalidate()
    → Render tick (30fps timer) → Draw callbacks render widgets into region buffers
    → Compositor blits dirty regions into main buffer → Buffer.diff(previous)
    → AnsiWriter.flush(updates[]) → Sync updates to previous buffer
```

### How Input Flows
```
STDIN (raw mode) → React Loop::addReadStream
    → InputReader reads 8KB chunks → EventParser (byte state machine)
    → KeyEvent/MouseEvent → Surface.dispatchInput()
    → Registered handlers (onMessage) → InputLine.handleKey()
    → Region.invalidate() → Next render tick picks it up
```

### Anti-Deadlock Rules
1. Surface.start() BEFORE scope->concurrent() — register timers before fiber suspends
2. async() wrapper for fiber-requiring operations in event loop callbacks
3. try/finally around concurrent() for guaranteed terminal restoration
4. Never block in event callbacks — no await, no sleep, no file_get_contents
5. All closures must be static — no $this capture in async contexts

### The STDIN Ownership Constraint
Raw sentinel uses StdinReader (cooked mode, line-buffered). TUI uses Surface's InputReader (raw mode, byte-level). They CANNOT share STDIN. This is why sentinel and sentinel-tui are separate commands, not a --tui flag.
