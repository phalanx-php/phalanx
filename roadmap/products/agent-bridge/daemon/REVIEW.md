# Daemon Spec Review -- API Verification & Integration Alignment

Reviewed against:
- `daemon/SPEC.md` (daemon internals)
- `integration/SPEC.md` (wire protocol contract)
- `extension/SPEC.md` (extension internals)
- `SPEC.md` (product spec / class designs)
- Live Phalanx framework source (`phalanx/packages/`)

---

## Section 1: API Verification

### 1.1 `Channel::withPressure(callable $fn): self`

**Spec usage (daemon/SPEC.md Section 3.7):**
```php
$this->inbound->withPressure(static function (bool $paused) use ($session, $tabId): void { ... });
```

**Verdict: YES -- exists with exact signature.**

Source: `phalanx-styx/src/Channel.php:146-151`. Returns `self`, accepts `callable`. The callback receives `bool $paused` -- `true` when buffer hits capacity, `false` when it drains to 50%. The hysteresis thresholds are hardcoded at 100% (pause) and 50% (resume) of `bufferSize`.

**Impact:** The daemon spec's `BridgeConfig::throttleThreshold` (48, 75% of 64) and `BridgeConfig::resumeThreshold` (32, 50% of 64) are config values that the daemon never actually passes to Channel. Channel uses its own fixed thresholds: pause at `bufferSize` (64), resume at `bufferSize * 0.5` (32). The throttle threshold of 48 in config is dead -- Channel will not pause until the buffer hits 64, not 48. Either the config is aspirational (planning to override Channel behavior) or it is wrong. The resume threshold of 32 happens to match Channel's 50% hysteresis, so that one is accidentally correct.

### 1.2 `ScopedStream::from(ExecutionScope, source)`

**Spec usage (daemon/SPEC.md Section 3.2):**
```php
$pipeline = ScopedStream::from(
    $this->scope,
    Emitter::produce(static function (Channel $ch, StreamContext $ctx) use ($inbound): void { ... }),
)
```

**Verdict: YES -- exists with exact signature.**

Source: `phalanx-styx/src/ScopedStream.php:36-38`. Accepts `ExecutionScope $scope` and `ReadableStreamInterface|StreamSource|Closure $source`. `Emitter` is a `StreamSource`, so passing an `Emitter::produce()` result works.

**Impact:** None. The chained operators (`.filter()`, `.throttle()`, `.bufferWindow()`, `.onEach()`) all exist on `ScopedStream` and delegate to `Emitter` internals.

### 1.3 `Emitter::produce(callable $producer): self`

**Spec usage (daemon/SPEC.md Section 3.2):**
```php
Emitter::produce(static function (Channel $ch, StreamContext $ctx) use ($inbound): void {
    foreach ($inbound->consume() as $msg) {
        $ctx->throwIfCancelled();
        $ch->emit($msg);
    }
})
```

**Verdict: YES -- exists with exact signature.**

Source: `phalanx-styx/src/Emitter.php:107-119`. Accepts `callable(Channel, StreamContext): void`. The producer runs inside `async()`, and the Channel completes automatically in the `finally` block when the producer returns or throws.

**Impact:** None. The daemon spec's usage pattern matches the framework's intended pattern exactly.

### 1.4 `Tool` interface with `$description` property hook

**Spec usage (daemon/SPEC.md Section 4):**
```php
final class ClassifyElements implements Tool
{
    public string $description {
        get => 'Classify DOM elements...';
    }
```

**Verdict: YES -- exists with exact interface.**

Source: `phalanx-athena/src/Tool/Tool.php`. The interface is:
```php
interface Tool extends Scopeable {
    public string $description { get; }
}
```

`Tool` extends `Scopeable`, so the `__invoke(Scope $scope)` signature is correct.

**Impact:** None.

### 1.5 `ToolOutcome::done()`, `ToolOutcome::retry()`, `ToolOutcome::data()`

**Spec usage (daemon/SPEC.md Section 4.1-4.4):**
```php
return ToolOutcome::done(array_values($valid));
return ToolOutcome::retry('No steps provided...');
return ToolOutcome::data([...]);
```

**Verdict: YES -- all three exist with exact signatures.**

Source: `phalanx-athena/src/Tool/ToolOutcome.php`.
- `done(mixed $data, string $reason = '')` -- `Disposition::Terminate`
- `retry(string $hint)` -- `Disposition::Retry`
- `data(mixed $data)` -- `Disposition::Continue`

**Impact:** None.

### 1.6 `$scope->timeout()`

**Spec usage (daemon/SPEC.md Section 5.4):**
```php
return $this->scope->timeout(
    Task::of(static fn(ExecutionScope $s) => $s->await($deferred->promise())),
    $this->scope->service(BridgeConfig::class)->actionTimeoutSeconds,
);
```

**Verdict: CLOSE -- exists but with reversed argument order.**

Source: `phalanx-aegis/src/TaskExecutor.php:49`:
```php
public function timeout(float $seconds, Scopeable|Executable $task): mixed;
```

The real signature is `timeout(float $seconds, Scopeable|Executable $task)` -- seconds first, task second. The daemon spec has them reversed: `timeout($task, $seconds)`.

**Impact:** This is a compile-time error. Every call site in the daemon spec that uses `$scope->timeout()` has the arguments swapped.

Note: The product spec (`SPEC.md` TabScope::executeAction) uses `$this->scope->await($deferred->promise())` directly without timeout wrapping. The daemon spec added the timeout wrapper but got the argument order wrong.

### 1.7 `$scope->attribute()`

**Spec usage (daemon/SPEC.md Section 4.4):**
```php
$tabScope = $scope->attribute('tabScope');
```

**Verdict: YES -- exists on `Scope` interface.**

Source: `phalanx-aegis/src/Scope.php:26`:
```php
public function attribute(string $key, mixed $default = null): mixed;
```

**Impact:** None. The `$scope->withAttribute('tabScope', $tabScope)` call in the spec also exists on `Scope` (returns `Scope`) and on `ExecutionScope` (returns `ExecutionScope`). The GeneratorAgent caller would need to use `ExecutionScope::withAttribute()` to get an `ExecutionScope` back, which is correct.

### 1.8 `$scope->execute()` accepting `Task` or `AgentDefinition`

**Spec usage (daemon/SPEC.md Section 3.2, 3.4, 5.7):**
```php
$this->scope->execute(Task::of(static fn(ExecutionScope $s) => $pipeline->consume()));
$result = $tab->scope->execute($classifier);
$result = $tab->scope->execute($repairAgent);
```

**Verdict: YES -- `execute()` accepts `Scopeable|Executable`.**

Source: `phalanx-aegis/src/TaskScope.php:12`:
```php
public function execute(Scopeable|Executable $task): mixed;
```

`Task::of()` returns a `Scopeable`. `AgentDefinition` extends `Executable`. Both are accepted.

However, `ClassifierAgent` and `RepairAgent` implement `AgentDefinition` which extends `Executable`. Their `__invoke(ExecutionScope $scope)` methods call `$scope->execute(Agent::from($this)->message(...)->maxSteps(...))`. `Agent::from()` returns a `Turn` object, and `Turn` does NOT implement `Scopeable` or `Executable`.

**Impact:** The `$scope->execute($turn)` call inside the agent definitions will fail at runtime. `Turn` is a configuration builder, not an executable task. There must be an `AgentLoop` or similar executor that accepts `Turn` objects. The daemon spec assumes `$scope->execute()` can accept a `Turn`, but the framework's `TaskScope::execute()` requires `Scopeable|Executable`. This needs investigation into how `AgentLoop` is actually invoked. The agent definitions' `__invoke()` methods are likely wrong in the spec -- they should probably use `AgentLoop::run()` or similar, not `$scope->execute($turn)`.

### 1.9 `WsConfig` -- existence and parameters

**Spec usage (daemon/SPEC.md Section 1.1):**
```php
new WsConfig(
    pingInterval: 15.0,
    maxMessageSize: 4 * 1024 * 1024,
)
```

**Verdict: YES -- exists but parameter mismatch.**

Source: `phalanx-websocket/src/WsConfig.php:11-19`:
```php
public function __construct(
    public private(set) int $maxMessageSize = 65536,
    public private(set) int $maxFrameSize = 65536,
    public private(set) float $pingInterval = 30.0,
    ...
)
```

`maxMessageSize` is `int`, not accepting a math expression at the type level (though `4 * 1024 * 1024` evaluates to an int, so this is fine). `pingInterval` default is 30.0, the spec overrides to 15.0.

**Impact:** None functionally. The spec's 4MB `maxMessageSize` is valid. Note `WsConfig` extends `HandlerConfig` -- the spec doesn't use this but it shouldn't cause issues.

### 1.10 `Runner::listen()` vs `Runner::run()`

**Spec usage (daemon/SPEC.md Section 1.1):**
```php
Runner::from($app, requestTimeout: 0.0)
    ->withWebsockets([...])
    ->listen("0.0.0.0:{$config->port}");
```

**Verdict: NO -- `listen()` does not exist. The method is `run()`.**

Source: `phalanx-stoa/src/Runner.php:98`:
```php
public function run(?string $listen = '0.0.0.0:8080'): int
```

**Impact:** The daemon spec's entry point will fail. Replace `->listen(...)` with `->run(...)`. Also the return type is `int` (exit code), not `AppHost` as implied by the spec's return type hint.

### 1.11 `Runner::withWebsockets()` -- argument type

**Spec usage (daemon/SPEC.md Section 1.1):**
```php
->withWebsockets([
    'WS /bridge' => new WsRoute(
        fn: new BridgeGateway(),
        config: new WsConfig(...),
    ),
])
```

**Verdict: NO -- `withWebsockets()` accepts `WsRouteGroup`, not an array.**

Source: `phalanx-stoa/src/Runner.php:72-76`:
```php
public function withWebsockets(WsRouteGroup $wsRoutes): self
```

The spec passes an associative array. The real API expects a `WsRouteGroup` object, which is the websocket equivalent of `RouteGroup`.

**Impact:** The entry point code is structurally wrong. It needs:
```php
->withWebsockets(WsRouteGroup::of([
    'WS /bridge' => new WsRoute(fn: new BridgeGateway(), config: new WsConfig(...)),
]))
```

### 1.12 `WsRoute` constructor -- `config` parameter

**Spec usage (daemon/SPEC.md Section 1.1):**
```php
new WsRoute(fn: new BridgeGateway(), config: new WsConfig(...))
```

**Verdict: YES -- exists with exact signature.**

Source: `phalanx-websocket/src/WsRoute.php:14-15`:
```php
public function __construct(public Closure|Scopeable|Executable $fn, public WsConfig $config = new WsConfig())
```

**Impact:** None.

### 1.13 `SchemaGenerator` -- derives tool schemas from constructor params

**Spec usage (daemon/SPEC.md Section 4.5):**
> The SchemaGenerator derives JSON Schema from constructor parameters

**Verdict: YES -- exists with exact behavior described.**

Source: `phalanx-athena/src/Tool/SchemaGenerator.php`. `generate(string $class)` reflects on the constructor, maps PHP types to JSON Schema types, reads `#[Param]` attributes for descriptions, and caches results.

**Impact:** None.

### 1.14 `AgentResult::$data`

**Spec usage (daemon/SPEC.md Section 3.4):**
```php
if ($result instanceof AgentResult && $result->data !== null) { ... }
```

**Verdict: NO -- `AgentResult` has no `$data` property.**

Source: `phalanx-athena/src/AgentResult.php`. The class has: `$text`, `$structured`, `$conversation`, `$usage`, `$steps`. There is no `$data` property.

Tool outcomes with `Disposition::Terminate` produce an `AgentResult` via `fromTool()`, where the tool's `$outcome->data` is serialized into `$text` (if string) or json-encoded into `$text`. The structured output goes through `$structured`.

**Impact:** Every place the daemon spec reads `$result->data` is wrong. The classification data would be in `$result->text` (as a JSON string) or `$result->structured` (if structured output was configured). The spec needs to either decode `$result->text` or configure the agent to use structured output.

### 1.15 `$this->appScope->createScope($cancel)`

**Spec usage (SPEC.md TabManager::connectTab):**
```php
$scope = $this->appScope->createScope($cancel);
```

**Verdict: NO -- `createScope()` is on `AppHost` and `Application`, not on `ExecutionScope`.**

Source: `phalanx-aegis/src/AppHost.php:16`:
```php
public function createScope(?CancellationToken $token = null): ExecutionScope;
```

`TabManager` holds `private readonly ExecutionScope $appScope`. `ExecutionScope` does not have `createScope()`. Only `AppHost` and `Application` do.

**Impact:** The `TabManager` either needs to hold an `AppHost` reference (not just `ExecutionScope`) or use a different mechanism to create child scopes. The `ExecutionScope` has `execute()` which creates child fibers, but not full child scopes with their own cancellation tokens.

### 1.16 Entry point return type

**Spec usage (daemon/SPEC.md Section 1.1):**
```php
return static function (array $context): \Phalanx\AppHost {
```

**Verdict: NO -- `Runner::run()` returns `int`, and the closure return should be `int` not `AppHost`.**

Source: `Runner::run()` returns `int` (exit code). The `symfony/runtime` expects the closure to return either an `AppHost` (which has its own runner logic) or an `int`. But the spec chains `Runner::from($app)->...->listen()` which doesn't return an `AppHost`. If the closure returns the result of `run()`, it should return `int`.

**Impact:** The return type annotation is wrong. The product spec's entry point (`SPEC.md`) has the same issue.

---

## Section 2: Integration Alignment

### 2.1 dom.response Routing Bug

**The bug:**

In `BridgeGateway::pump()` (both `SPEC.md` and `daemon/SPEC.md`), the message router dispatches by type prefix:
```php
str_starts_with($msg->type, 'dom.') => $tabManager->handleDomMessage($msg, $session),
```

`handleDomMessage` does:
```php
$this->tabs[$msg->tabId]?->inbound->emit($msg);
```

This means ALL `dom.*` messages -- including `dom.response` -- are emitted into the inbound Channel and enter the stream pipeline.

But `dom.response` is a reply to a `dom.request`. It carries a `requestId` and should resolve a pending Deferred in `TabScope::$pendingActions`. Instead, it enters the stream pipeline where the filter operator drops it (the filter passes only `dom.snapshot`, `dom.mutations`, `net.response`). The Deferred for the `dom.request` never resolves, and `TabScope::queryDom()` hangs until the action timeout fires.

**The fix:**

`handleDomMessage` must check the message type and route `dom.response` to `handleActionResult` (which resolves Deferreds), not to the inbound Channel. Or add a separate handler:

```php
public function handleDomMessage(BridgeMessage $msg, ExtensionSession $session): void
{
    if ($msg->type === 'dom.response') {
        $this->tabs[$msg->tabId]?->handleDomResponse($msg);
        return;
    }
    $this->tabs[$msg->tabId]?->inbound->emit($msg);
}
```

Where `handleDomResponse` resolves the Deferred keyed by `requestId`, similar to `handleActionResult` resolving by `actionId`.

**Severity:** Critical. `queryDom()` is used by `ValidateSelector` (tool) and `requestRepair()`. Neither will work until this is fixed.

**Additional detail:** The `handleActionResult` method reads `actionId` from `$msg->payload['actionId']`, but `dom.response` uses `requestId`, not `actionId`. The `TabScope::queryDom()` method stores the deferred under the key `'dreq_N'` (the requestId). So `handleActionResult` cannot handle `dom.response` as-is even if the routing were fixed -- a separate handler is needed that reads `$msg->payload['requestId']`.

### 2.2 user.chat -- Extension Sends, Daemon Has No Handler

**The issue:**

The extension spec (`extension/SPEC.md` line 1601-1621) defines a `send-chat.ts` message handler that sends:
```typescript
sendToDaemon({ type: "user.chat", tabId, text } as any)
```

The `as any` cast and the comment "extension of the protocol for side panel input" confirm this message type is not in the integration spec's wire protocol table.

In the daemon's `BridgeGateway::pump()` router:
```php
str_starts_with($msg->type, 'user.') => $tabManager->handleUserAction($msg, $session),
```

`user.chat` starts with `user.`, so it will be routed to `handleUserAction`. `handleUserAction` does:
```php
$this->policyStore->recordUserAction($this->domain, $msg->payload);
$this->inbound->emit($msg);
```

This records a chat message as a "user action" in the policy store (wrong -- it is a conversation input, not a click/type/scroll observation) and emits it into the stream pipeline. The stream pipeline's filter drops it (only passes `dom.snapshot`, `dom.mutations`, `net.response`).

**Result:** User chat messages from the side panel are silently discarded by the daemon. They pollute the policy store with non-action data. The user types a message in the conversation UI, sees nothing happen, and has no feedback.

**The fix needs three things:**
1. Add `user.chat` to the integration spec as a formal message type.
2. Add a `handleUserChat` method in `TabManager` or a separate router branch for `user.chat`.
3. The handler should invoke the `GeneratorAgent` or a dedicated conversational agent, not record it as a policy action.

### 2.3 Content Script Port Lifecycle on Full Navigation

**Traced through both specs:**

1. User navigates tab to a different origin (full page load).
2. Chrome destroys the content script immediately. No `dom.snapshot`, no `action.result`, nothing.
3. Content script's `chrome.runtime.Port.onDisconnect` fires in the service worker.
4. Service worker's `handlePortDisconnect` checks if tab still exists via `chrome.tabs.get(tabId)`.
5. Tab exists (it navigated, not closed). Service worker sends `tab.disconnect` to daemon.
6. Daemon receives `tab.disconnect`, calls `TabManager::disconnectTab(tabId)`.
7. `TabScope::dispose()` runs: rejects pending Deferreds, completes Channel, cancels token.
8. Service worker detects it should re-inject (tab within user-connected scope): re-injects content script.
9. New content script connects port, starts observing.
10. Content script sends `tab.connect` via service worker.
11. Service worker forwards `tab.connect` to daemon.
12. Daemon creates new `TabScope` for the same `tabId`.
13. Content script sends `dom.snapshot`.
14. Daemon feeds snapshot into new stream pipeline. Normal operation resumes.

**Gaps identified:**

**Gap A:** Between steps 6 and 11, the daemon has no TabScope for this tabId. Any messages from other tabs that reference this tab (unlikely but possible in future multi-tab correlation) would hit null safety operators and be silently dropped. This is fine.

**Gap B:** Step 8 assumes the service worker re-injects automatically. The extension spec (Section 2.4) says: "Tab navigated (full): sends tab.disconnect for old context. If the new page is within the scope the user connected, re-injects content script and sends new tab.connect." But the extension spec does not define what "within the scope the user connected" means or how the service worker tracks it. The `connectedTabs` storage only stores tabIds, not the original connection scope/pattern. This means the service worker either always re-injects (simplest, likely correct) or needs additional state to decide.

**Gap C:** If the full navigation goes to a `chrome://` or `chrome-extension://` URL, `chrome.scripting.executeScript()` will fail. The service worker must handle this rejection and send `tab.disconnect` without re-injection.

### 2.4 action.result Payload Parsing

**The extension sends:**
```typescript
interface ActionResult extends BaseMessage {
  type: "action.result"
  tabId: number
  actionId: string
  success: boolean
  data?: Record<string, unknown>
  error?: string
}
```

Note: `actionId` is a top-level field, not nested in a payload object.

**The daemon receives (via BridgeMessage::fromJson):**
```php
$type = $data['type'];
// ...
payload: array_diff_key($data, array_flip(['type', 'tabId', 'url', 'title', 'timestamp'])),
```

`actionId` is NOT in the exclusion list (`['type', 'tabId', 'url', 'title', 'timestamp']`), so it ends up in `$msg->payload['actionId']`.

**The daemon reads it in `TabScope::handleActionResult`:**
```php
$id = $msg->payload['actionId'] ?? null;
```

**This works.** The `actionId` field ends up in the payload and is read from there. Same for `success`, `data`, `error`.

However, `dom.response` sends `requestId` as a top-level field. `BridgeMessage::fromJson` puts it into `payload['requestId']`. If the `dom.response` routing bug (Section 2.1) were fixed and a `handleDomResponse` method were added, it would need to read `$msg->payload['requestId']` -- which is correct given the fromJson behavior.

### 2.5 Every Message the Extension Sends -- Does the Daemon Handle It?

| Message Type | Extension Sends | Daemon Routes To | Handled Correctly |
|---|---|---|---|
| `tab.connect` | Yes | `handleTabMessage` -> `connectTab` | YES |
| `tab.disconnect` | Yes | `handleTabMessage` -> `disconnectTab` | YES |
| `tab.navigate` | Yes | `handleTabMessage` -> `handleNavigation` | YES |
| `dom.snapshot` | Yes | `handleDomMessage` -> `inbound->emit()` | YES |
| `dom.mutations` | Yes | `handleDomMessage` -> `inbound->emit()` | YES |
| `dom.response` | Yes | `handleDomMessage` -> `inbound->emit()` | **BUG** -- should resolve Deferred |
| `net.request` | Yes | `handleNetMessage` -> `inbound->emit()` | YES (but filtered out by pipeline) |
| `net.response` | Yes | `handleNetMessage` -> `inbound->emit()` | YES |
| `user.action` | Yes | `handleUserAction` | YES |
| `user.chat` | Yes | `handleUserAction` (prefix match) | **BUG** -- wrong handler, silently lost |
| `action.result` | Yes | `handleActionResult` | YES |
| `flow.pressure` | Yes | `handleFlowControl` | PARTIAL -- handler body is empty comment |

### 2.6 Every Message the Daemon Sends -- Does the Extension Handle It?

| Message Type | Daemon Sends | Extension Routes To | Handled |
|---|---|---|---|
| `action.execute` | Yes | Service worker -> content script port | YES |
| `action.cancel` | Yes | Service worker -> content script port | YES |
| `dom.request` | Yes | Service worker -> content script port | YES |
| `ui.update` | Yes | Service worker -> side panel | YES |
| `flow.throttle` | Yes | Service worker -> content script port | YES |
| `flow.resume` | Yes | Service worker -> content script port | YES |

### 2.7 flow.pressure Handler is Empty

The daemon spec's `TabScope::handleFlowControl` (line 913-916 in SPEC.md):
```php
public function handleFlowControl(BridgeMessage $msg): void
{
    // Extension reporting buffer pressure.
    // Channel backpressure handles daemon-side congestion.
}
```

This is a no-op. The integration spec (Section 4.2, Stage 2) says the daemon should use `flow.pressure` to decide whether to send `flow.throttle`. But the daemon's actual throttle/resume mechanism is driven by Channel hysteresis (`withPressure` callback), not by the extension's reported buffer depth.

This means there are two independent pressure signals:
1. Extension reports its buffer depth via `flow.pressure` (currently ignored by daemon).
2. Daemon's Channel backpressure fires `withPressure` callback when its own buffer fills.

The daemon only acts on #2. The extension's `flow.pressure` message is received, routed, and discarded. This is a design gap: the extension could be overwhelmed (buffer at 90%) while the daemon's Channel is still half-empty. The daemon would not throttle in this case.

---

## Section 3: Phalanx Footguns

### 3.1 Static Closure Audit

**daemon/SPEC.md closures:**

| Location | Static? | Issue |
|---|---|---|
| Section 1.1: `return static function (array $context): ...` | YES | Good |
| Section 1.2: `writeLockfile` is a `private static function` | N/A | Good |
| Section 1.3: Signal handler closures | YES | Good |
| Section 1.3: `register_shutdown_function($cleanup)` | YES | Good (uses closure from `static function ()`) |
| Section 2.1: `BridgeServiceBundle` factory closures | YES | Good |
| Section 3.2: `Emitter::produce(static function ...)` | YES | Good |
| Section 3.2: `.filter(static function ...)` | YES | Good |
| Section 3.2: `.onEach(static function ...)` | YES | Good |
| Section 3.4: `classifyBatch` is `private static function` | N/A | Good |
| Section 3.5: `executeClassifications` is `private static function` | N/A | Good |
| Section 3.7: `withPressure(static function ...)` | YES | Good |
| Section 5.2: Transport close handler | YES | Good |
| Section 5.4: `Task::of(static fn ...)` | YES | Good |
| Section 5.7: `requestRepair` is `private static function` | N/A | Good |

**Product spec (SPEC.md) closures:**

| Location | Static? | Issue |
|---|---|---|
| `BridgeGateway::__invoke` `$scope->onDispose(static function ...)` | YES | Good |
| `BridgeServiceBundle` factory closures | YES | Good |

**Verdict:** All closures in both specs are properly `static`. No violations found.

### 3.2 Reference Cycle: TabScope -> Pipeline -> ClassifierAgent

**Chain analysis:**

```
TabScope
  -> $this->inbound (Channel)
  -> $this->scope (ExecutionScope)
  -> $this->session (ExtensionSession)
  -> $this->legoLibrary, $this->policyStore

Pipeline (runs in child fiber):
  -> captures $inbound (Channel) via `use ($inbound)` in static closure
  -> captures $tabScope via `use ($tabScope)` in .onEach() callback

classifyBatch(TabScope $tab, array $batch):
  -> receives TabScope as parameter
  -> reads $tab->scope, $tab->domain, $tab->session
```

**The risk:**

In `startPipeline()`:
```php
$tabScope = $this;
->onEach(static function (mixed $batch) use ($tabScope): void {
    self::classifyBatch($tabScope, $batch);
})
```

The `$tabScope` variable is captured by the `onEach` closure. This closure is stored inside the Emitter's operator chain, which is running in a fiber spawned by `$this->scope->execute()`. The fiber holds the closure, the closure holds `$tabScope`, `$tabScope` holds `$this->scope` which holds the fiber.

**Cycle:** `TabScope -> scope -> fiber -> Emitter chain -> onEach closure -> $tabScope -> TabScope`

This is a reference cycle. However, `TabScope::dispose()` breaks it explicitly:
1. `$this->inbound->complete()` causes the pipeline's consume loop to exit.
2. `$this->cancellation->cancel()` interrupts any in-flight await.
3. `$this->scope->dispose()` disposes the child scope, which should release the fiber.

The cycle exists but is broken deterministically by `dispose()`. This is acceptable IF `dispose()` is always called. It is called in `TabManager::disconnectTab()` and in the session unregister path. The question is whether there is any path where a TabScope is abandoned without `dispose()`.

**Potential issue:** If `TabManager::$tabs` still holds a reference to the TabScope after the session is gone (e.g., if `unregisterSession` throws partway through), the TabScope and its cycle survive. The `unregisterSession` method iterates `$session->ownedTabIds()` and calls `disconnectTab()` for each. If one `disconnectTab` throws (e.g., TabScope::dispose throws), the remaining tabs for that session are not cleaned up. A try/catch around each iteration would be safer.

### 3.3 `withPressure` Callback Safety

**The closure:**
```php
$this->inbound->withPressure(static function (bool $paused) use ($session, $tabId): void {
    if ($paused) {
        $session->send(BridgeCommand::throttle($tabId, maxEventsPerSec: 5));
    } else {
        $session->send(BridgeCommand::resume($tabId));
    }
});
```

**Analysis:**
- The closure is `static` -- good.
- It captures `$session` (ExtensionSession) and `$tabId` (int).
- `$session` holds a `WsConnection`. If the WebSocket has closed, `$session->send()` calls `$this->connection->sendText()` which calls `$this->outbound->emit()`. If the outbound Channel is complete, the emit is a no-op (Channel::emit checks `$this->open` first).
- The closure is stored in Channel's `$pressureCallback` field. Channel does not clear this callback in `complete()` or `error()`. The reference to `$session` survives Channel completion.

**Issue:** After `TabScope::dispose()` completes the Channel, the Channel still holds a reference to the `$session` via the pressure callback. This prevents `$session` from being garbage collected until the Channel is also collected. Since TabScope holds the Channel (`$this->inbound`), and TabScope is removed from `TabManager::$tabs` in `disconnectTab()`, the reference chain should be broken when `disconnectTab` runs `unset($this->tabs[$tabId])`. But the fiber running the pipeline may still hold a reference to the Channel's generator in its call stack until the fiber is fully collected.

**Severity:** Low. The pressure callback holds `$session` which holds `WsConnection`. If the session is for a disconnected WebSocket, the connection's channels are already complete. The memory cost is small and will be collected when the fiber is collected. But strictly speaking, Channel should clear its `$pressureCallback` in `complete()` to be safe.

### 3.4 Channel Hysteresis vs Spec's Description

**What the spec says (daemon/SPEC.md Section 3.7):**
> Channel hysteresis: pauses producer when buffer reaches 64 items, resumes when buffer drains to 32 items (50%).

**What the framework actually does (Channel.php):**

Pause (line 59-68):
```php
if (count($this->buffer) >= $this->bufferSize) {
    if ($this->pressureCallback !== null && !$this->paused) {
        $this->paused = true;
        ($this->pressureCallback)(true);
    }
    // Producer suspends via Deferred
}
```

Resume (line 109-119):
```php
if (count($this->buffer) < (int) ($this->bufferSize * 0.5)) {
    if ($this->paused && $this->pressureCallback !== null) {
        $this->paused = false;
        ($this->pressureCallback)(false);
    }
    // Producer Deferred resolved
}
```

**Verdict:** The spec's description matches the framework. Pauses at `bufferSize` (64), resumes at `bufferSize * 0.5` (32). The pressure callback fires at the same thresholds as the producer suspend/resume. The spec is accurate.

**However:** The `BridgeConfig::throttleThreshold` (48) and `BridgeConfig::resumeThreshold` (32) config values are never used by Channel. Channel's thresholds are derived from `bufferSize` alone. These config values appear to be aspirational -- they describe desired behavior that doesn't match the framework's implementation. They should either be removed from config or Channel needs to be extended to support custom thresholds.

### 3.5 Deferred Cleanup Paths

**`TabScope::executeAction()`:**
```php
$deferred = new Deferred();
$this->pendingActions[$actionId] = $deferred;
try {
    return $this->scope->await($deferred->promise());
} finally {
    unset($this->pendingActions[$actionId]);
}
```

The `finally` block ensures cleanup. If `await` throws (cancellation, timeout), the Deferred is still removed.

**`TabScope::queryDom()`:**
Same pattern. `finally` block removes from `$pendingActions`.

**`TabScope::dispose()`:**
```php
foreach ($this->pendingActions as $deferred) {
    $deferred->reject(new \RuntimeException('Tab disconnected'));
}
$this->pendingActions = [];
```

Rejects all pending Deferreds and clears the map.

**Potential issue:** There is a race between `dispose()` rejecting a Deferred and the `finally` block in `executeAction` trying to `unset` from `$pendingActions`. If `dispose()` runs while `executeAction` is awaiting:

1. `dispose()` rejects the Deferred.
2. The `await()` in `executeAction` throws (the rejection propagates).
3. The `finally` block runs `unset($this->pendingActions[$actionId])`.
4. But `dispose()` already set `$this->pendingActions = []`.
5. `unset` on a key that doesn't exist is a no-op in PHP.

This is safe. No double-free, no exception, no leak.

**Deferred without canceller:**

The daemon spec explicitly addresses this in Section 5.8: Deferreds are created without a canceller, which is acceptable because cleanup is handled by `finally` and `dispose()`. This is correct. A canceller would be redundant and add complexity.

### 3.6 Blocking I/O in the Event Loop

**`LegoLibrary` and `PolicyStore` use synchronous file I/O:**
- `file_get_contents()`, `file_put_contents()`, `json_decode()`, `json_encode()`
- `glob()`, `is_dir()`, `mkdir()`, `file_exists()`, `unlink()`

These block the event loop. For the expected workload (small JSON files, local filesystem), the latency is sub-millisecond and acceptable. But on a slow filesystem (network mount, encrypted volume), these could stall all concurrent operations.

The daemon spec acknowledges this implicitly by choosing file-based storage for simplicity. The product spec's "Why File-Based Storage" section says "No async I/O wrapper needed." This is a conscious tradeoff -- acknowledged but worth flagging.

### 3.7 `writeLockfile` Uses Blocking I/O at Startup

`writeLockfile()` (daemon/SPEC.md Section 1.2) uses `file_get_contents`, `json_decode`, `posix_kill`, `file_put_contents` -- all blocking. But this runs before the event loop starts (before `Runner::run()`), so it cannot stall concurrent operations. This is correct.

### 3.8 `handleUserAction` Emits to Inbound AND Calls PolicyStore

```php
public function handleUserAction(BridgeMessage $msg): void
{
    $this->policyStore->recordUserAction($this->domain, $msg->payload);
    $this->inbound->emit($msg);
}
```

`policyStore->recordUserAction()` does synchronous file I/O (reads JSON, deserializes, appends, re-serializes, writes JSON) on every user action. This runs in the `BridgeGateway::pump()` fiber, which is the main message processing loop. A slow disk write here blocks processing of ALL subsequent messages for ALL tabs on this connection.

**Severity:** Medium. User actions are infrequent (clicks, types) compared to DOM mutations, so the practical impact is low. But the architectural concern is real: a blocking operation in the pump loop creates a bottleneck for all message processing. Consider deferring the policy write to a background fiber.

---

## Summary of Critical Issues

1. **`dom.response` routing bug** (Section 2.1) -- `queryDom()` will hang forever. Must route `dom.response` to Deferred resolution, not stream pipeline.

2. **`$scope->timeout()` argument order** (Section 1.6) -- reversed in daemon spec. Will fail at compile time.

3. **`Runner::listen()` does not exist** (Section 1.10) -- should be `Runner::run()`.

4. **`Runner::withWebsockets()` expects `WsRouteGroup`** (Section 1.11) -- not an array.

5. **`AgentResult::$data` does not exist** (Section 1.14) -- classification results are in `$text` or `$structured`.

6. **`$scope->execute($turn)` is invalid** (Section 1.8) -- `Turn` is not `Scopeable|Executable`.

7. **`$appScope->createScope()` is not on `ExecutionScope`** (Section 1.15) -- needs `AppHost`.

8. **`user.chat` silently discarded** (Section 2.2) -- extension sends it, daemon routes to wrong handler.

9. **`flow.pressure` handler is empty** (Section 2.7) -- extension reports pressure, daemon ignores it.

10. **`BridgeConfig` throttle/resume thresholds are unused** (Section 1.1, 3.4) -- Channel uses hardcoded thresholds.
