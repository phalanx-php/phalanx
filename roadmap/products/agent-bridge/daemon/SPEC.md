# Phalanx Agent Bridge -- Daemon Spec

Everything inside the PHP daemon process. For wire protocol, failure modes, backpressure stages, and state reconciliation, see `../integration/SPEC.md`. This spec does not duplicate that contract -- it builds on it.

---

## 1. Process Lifecycle

### 1.1 Entry Point

The daemon boots directly via Composer's `autoload.php`. Configuration comes from `$_SERVER + $_ENV` merged into the `Application::starting()` call. The entry point is a plain script, not a closure -- `Runner::run()` returns `int` (exit code), which is incompatible with `symfony/runtime`'s expectation of an object return.

```php
<?php

// bin/bridge

require_once __DIR__ . '/../vendor/autoload.php';

$app = Application::starting($_SERVER + $_ENV)
    ->providers(
        new BridgeServiceBundle(),
    )
    ->compile();

$config = $app->scope()->service(BridgeConfig::class);
$app->scope()->service(TabManager::class)->setApp($app);

// Lockfile and signal handlers (Sections 1.2, 1.3)
// ...

Runner::from($app, requestTimeout: 0.0)
    ->withWebsockets(WsRouteGroup::of([
        'WS /bridge' => new WsRoute(
            fn: new BridgeGateway(),
            config: new WsConfig(
                pingInterval: 15.0,
                maxMessageSize: 4 * 1024 * 1024, // 4MB for large DOM snapshots
            ),
        ),
    ]))
    ->run("0.0.0.0:{$config->port}");
```

Boot sequence:

1. `autoload.php` loads the Composer autoloader.
2. `Application::starting($_SERVER + $_ENV)` creates `ApplicationBuilder` with merged environment.
3. `BridgeServiceBundle` registers services with `$context` values (AI services registered separately when needed).
4. `compile()` resolves the service graph, produces `AppHost`.
5. `TabManager::setApp($app)` provides the scope factory (AppHost is not available during service registration -- see Section 2.1).
6. Lockfile written (Section 1.2).
7. Signal handlers registered (Section 1.3).
8. `Runner::from()` starts the ReactPHP HTTP server with WebSocket upgrade support.
9. `run()` binds the port and enters the event loop. The process blocks here until shutdown.

### 1.2 Lockfile

Path: `~/.phalanx/daemon.lock`

Written immediately after `compile()`, before the server starts listening. The lockfile is the discovery mechanism -- the Chrome extension's Native Messaging host reads it to find the WebSocket URL.

```json
{
    "port": 9078,
    "pid": 48291,
    "started": "2026-04-04T14:30:00-05:00"
}
```

**Write sequence:**

```php
<?php

private static function writeLockfile(BridgeConfig $config): void
{
    $lockPath = $config->dataDir . '/daemon.lock';

    if (!is_dir($config->dataDir)) {
        mkdir($config->dataDir, 0755, true);
    }

    // Check for stale lockfile from unclean shutdown
    if (file_exists($lockPath)) {
        $existing = json_decode(file_get_contents($lockPath), true);
        $existingPid = $existing['pid'] ?? null;

        if ($existingPid !== null && posix_kill($existingPid, 0)) {
            throw new \RuntimeException(
                "Daemon already running (PID {$existingPid}). Kill it or remove {$lockPath}."
            );
        }
        // Stale lockfile from crashed process -- overwrite it
    }

    file_put_contents($lockPath, json_encode([
        'port' => $config->port,
        'pid' => getmypid(),
        'started' => date('c'),
    ], JSON_THROW_ON_ERROR));
}
```

**Stale lockfile detection:** On startup, if a lockfile exists, the daemon checks whether the recorded PID is alive via `posix_kill($pid, 0)`. If the process is alive, the daemon refuses to start (avoids port conflict). If the process is dead, the lockfile is stale from an unclean shutdown -- the daemon overwrites it.

### 1.3 Shutdown and Signal Handling

Clean shutdown removes the lockfile, disposes all scopes, and drains the WebSocket connections.

```php
<?php

private static function registerSignalHandlers(BridgeConfig $config): void
{
    $lockPath = $config->dataDir . '/daemon.lock';

    $cleanup = static function () use ($lockPath): void {
        @unlink($lockPath);
    };

    // Normal PHP shutdown (exit, fatal error, uncaught exception)
    register_shutdown_function($cleanup);

    // SIGTERM (systemd stop, kill default)
    Loop::addSignal(SIGTERM, static function () use ($cleanup): void {
        $cleanup();
        Loop::stop();
    });

    // SIGINT (Ctrl+C)
    Loop::addSignal(SIGINT, static function () use ($cleanup): void {
        $cleanup();
        Loop::stop();
    });
}
```

**What `Loop::stop()` triggers:**

1. The event loop ceases scheduling new ticks.
2. All active WebSocket connections receive a close frame (`WsCloseCode::GoingAway`).
3. `WsConnectionHandler`'s `onDispose` fires per connection, calling `WsGateway::unregister()`.
4. Each `BridgeGateway` pump loop exits when `WsConnection::inbound` completes.
5. `TabManager::unregisterSession()` disposes all TabScopes per session.
6. TabScope disposal cancels CancellationTokens, rejects pending Deferreds, completes Channels.
7. Lockfile is removed by the `register_shutdown_function` callback.

**Unclean shutdown (SIGKILL, power loss, OOM kill):** The lockfile remains on disk. The next daemon startup detects it as stale (Section 1.2). The extension's WebSocket closes at the TCP level (OS closes the socket when the process dies). The extension follows its standard reconnection protocol (see `../integration/SPEC.md` Section 2.2).

### 1.4 LaunchAgent (macOS Auto-Start)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.phalanx.agent-bridge</string>
    <key>ProgramArguments</key>
    <array>
        <string>/usr/local/bin/php</string>
        <string>/usr/local/share/phalanx/agent-bridge/bin/bridge</string>
    </array>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <dict>
        <key>SuccessfulExit</key>
        <false/>
    </dict>
    <key>StandardOutPath</key>
    <string>/usr/local/var/log/phalanx-bridge.log</string>
    <key>StandardErrorPath</key>
    <string>/usr/local/var/log/phalanx-bridge.log</string>
    <key>EnvironmentVariables</key>
    <dict>
        <key>BRIDGE_PORT</key>
        <string>9078</string>
    </dict>
</dict>
</plist>
```

Installed to `~/Library/LaunchAgents/com.phalanx.agent-bridge.plist`. `KeepAlive` with `SuccessfulExit: false` means launchd restarts the daemon if it crashes but not if it exits cleanly (manual stop via SIGTERM).

### 1.5 systemd User Service (Linux)

```ini
[Unit]
Description=Phalanx Agent Bridge Daemon
After=network.target

[Service]
Type=simple
ExecStart=/usr/local/bin/php /usr/local/share/phalanx/agent-bridge/bin/bridge
Restart=on-failure
RestartSec=5
Environment=BRIDGE_PORT=9078

[Install]
WantedBy=default.target
```

Installed to `~/.config/systemd/user/phalanx-bridge.service`. Enabled via `systemctl --user enable phalanx-bridge`.

---

## 2. Service Registration

### 2.1 BridgeServiceBundle

Every service is registered through `BridgeServiceBundle::services()`. Configuration flows from `$context` -- never `getenv()`, never `$_ENV`.

```php
<?php

declare(strict_types=1);

namespace AgentBridge;

use AgentBridge\Lego\LegoLibrary;
use AgentBridge\Policy\PolicyStore;
use AgentBridge\Tab\TabManager;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class BridgeServiceBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $dataDir = $context['BRIDGE_DATA_DIR']
            ?? ($context['HOME'] ?? '/tmp') . '/.phalanx';

        $services->singleton(BridgeConfig::class)
            ->factory(static fn(): BridgeConfig => new BridgeConfig(
                dataDir: $dataDir,
                port: (int) ($context['BRIDGE_PORT'] ?? 9078),
                actionTimeoutSeconds: (float) ($context['BRIDGE_ACTION_TIMEOUT'] ?? 30.0),
                classifierBufferCount: (int) ($context['BRIDGE_CLASSIFIER_BUFFER_COUNT'] ?? 20),
                classifierBufferSeconds: (float) ($context['BRIDGE_CLASSIFIER_BUFFER_SECONDS'] ?? 2.0),
                maxEventsPerSecThrottled: (int) ($context['BRIDGE_THROTTLED_EVENTS_PER_SEC'] ?? 5),
            ));

        $services->singleton(LegoLibrary::class)
            ->factory(static fn(): LegoLibrary => new LegoLibrary(
                basePath: $dataDir . '/legos',
            ));

        $services->singleton(PolicyStore::class)
            ->factory(static fn(): PolicyStore => new PolicyStore(
                basePath: $dataDir . '/policies',
            ));

        // TabManager receives AppHost via setApp() called from bin/bridge after compile().
        // AppHost cannot be injected through the service graph -- it is only available
        // after compile() returns. LegoLibrary and PolicyStore are auto-injected singletons.
        $services->singleton(TabManager::class)
            ->factory(static fn(LegoLibrary $legoLibrary, PolicyStore $policyStore): TabManager => new TabManager(
                legoLibrary: $legoLibrary,
                policyStore: $policyStore,
            ));
    }
}
```

### 2.2 Service Inventory

| Service | Lifetime | Initialization | Dependencies |
|---------|----------|----------------|--------------|
| `BridgeConfig` | singleton | eager | `$context` values only |
| `LegoLibrary` | singleton | eager | `BridgeConfig::dataDir` (via `$context`) |
| `PolicyStore` | singleton | eager | `BridgeConfig::dataDir` (via `$context`) |
| `TabManager` | singleton | eager | `LegoLibrary`, `PolicyStore` (constructor); `AppHost` via `setApp()` post-compile |
| `ProviderConfig` | singleton | eager | `$context` AI provider keys (registered by `AiServiceBundle`) |

**TabManager's `setApp()` pattern:** `AppHost` is not available during service registration because `compile()` has not yet returned. `TabManager` receives its auto-injectable dependencies (`LegoLibrary`, `PolicyStore`) through the container, then `bin/bridge` calls `setApp($app)` immediately after `compile()`. This is the only service that uses post-compile initialization -- all others are fully constructed by the container.

Note: The product spec shows `$_SERVER['HOME']` in the `BridgeServiceBundle`. This violates the `$context`-only rule. The corrected version above uses `$context['HOME']`, which is populated from `$_SERVER + $_ENV` passed to `Application::starting()`.

### 2.3 BridgeConfig

```php
<?php

declare(strict_types=1);

namespace AgentBridge;

final readonly class BridgeConfig
{
    public function __construct(
        public string $dataDir,
        public int $port = 9078,
        public float $actionTimeoutSeconds = 30.0,
        public int $classifierBufferCount = 20,
        public float $classifierBufferSeconds = 2.0,
        public int $maxEventsPerSecThrottled = 5,
    ) {}
}
```

Every numeric constant that affects backpressure or AI batch sizing is exposed as config. Hardcoding these inside stream operators makes tuning require code changes.

---

## 3. Stream Pipeline Design

### 3.1 Overview

Each connected tab gets its own stream pipeline. The pipeline transforms raw `BridgeMessage` events from the extension into structured batches suitable for AI classification. The pipeline is created when `TabManager::connectTab()` runs and disposed when the TabScope is disposed.

```
TabScope.inbound (Channel, bufferSize: 64)
    |
    | BridgeMessage objects emitted by TabManager::handleDomMessage/handleNetMessage
    v
Emitter::produce(...)  -- reads from Channel, yields BridgeMessages
    |
    .filter(...)       -- drop non-classifiable messages
    |
    .throttle(0.5)     -- max 2 events/sec during high activity
    |
    .bufferWindow(20, 2.0)  -- collect up to 20 events or 2 seconds
    |
    .map(...)          -- transform batch into ClassifierAgent input
    |
    ScopedStream::consume()  -- drives the pipeline, feeds ClassifierAgent
```

### 3.2 Pipeline Construction

The pipeline is constructed inside `TabScope::startPipeline()`, called after `TabScope` creation in `TabManager::connectTab()`.

```php
<?php

declare(strict_types=1);

namespace AgentBridge\Tab;

use AgentBridge\Agent\ClassifierAgent;
use AgentBridge\BridgeConfig;
use AgentBridge\BridgeMessage;
use AgentBridge\Lego\LegoLibrary;
use AgentBridge\Policy\PolicyStore;
use Phalanx\Stream\Channel;
use Phalanx\Stream\Contract\StreamContext;
use Phalanx\Stream\Emitter;
use Phalanx\Stream\ScopedStream;

// Inside TabScope:

public function startPipeline(): void
{
    $config = $this->scope->service(BridgeConfig::class);
    $inbound = $this->inbound;
    $tabScope = $this;

    $pipeline = ScopedStream::from(
        $this->scope,
        Emitter::produce(static function (Channel $ch, StreamContext $ctx) use ($inbound): void {
            foreach ($inbound->consume() as $msg) {
                $ctx->throwIfCancelled();
                $ch->emit($msg);
            }
        }),
    )
    ->filter(static function (mixed $msg): bool {
        assert($msg instanceof BridgeMessage);
        return match ($msg->type) {
            'dom.snapshot', 'dom.mutations', 'net.response' => true,
            default => false,
        };
    })
    ->throttle(0.5)
    ->bufferWindow($config->classifierBufferCount, $config->classifierBufferSeconds)
    ->onEach(static function (mixed $batch) use ($tabScope): void {
        assert(is_array($batch));
        self::classifyBatch($tabScope, $batch);
    });

    // Kick off consumption in a child fiber via the scope
    $this->scope->execute(\Phalanx\Task\Task::of(
        static fn(\Phalanx\ExecutionScope $s) => $pipeline->consume()
    ));
}
```

### 3.3 Operator Configuration and Rationale

| Operator | Config | Justification |
|----------|--------|---------------|
| `filter(...)` | Passes `dom.snapshot`, `dom.mutations`, `net.response` | `user.action` feeds PolicyStore directly (handled in `TabScope::handleUserAction`). `net.request` is low-signal without its response. `flow.pressure` is handled by `TabScope::handleFlowControl`. Only classifiable events enter the pipeline. |
| `throttle(0.5)` | 500ms minimum interval | At 60fps with active DOM mutation, the content script can produce 20-30 batched events per second. The classifier processes one batch every 2-5 seconds. Throttling to 2/sec prevents the bufferWindow from filling with redundant intermediate states. |
| `bufferWindow(20, 2.0)` | 20 events or 2 seconds, whichever comes first | Balances AI batch efficiency against user-perceived latency. 20 events gives the classifier enough context to make good classifications. 2 seconds is the upper bound on latency for a single classification cycle. |

**Why no `debounce`:** Debounce emits only the *last* value in a quiet period. For DOM events, we need the *batch* of all events during the window, not just the final one. `bufferWindow` serves this purpose. Debounce would discard intermediate mutations that contain classification-relevant state transitions.

### 3.4 Pipeline to ClassifierAgent

Each batch emitted by `bufferWindow` is fed to the `ClassifierAgent` synchronously within the consume loop. "Synchronously" here means the fiber awaits the AI response before consuming the next batch -- this is the natural backpressure mechanism described in `../integration/SPEC.md` Section 4.2, Stage 6.

```php
<?php

private static function classifyBatch(TabScope $tab, array $batch): void
{
    $legoLibrary = $tab->scope->service(LegoLibrary::class);
    $policyStore = $tab->scope->service(PolicyStore::class);

    $legos = $legoLibrary->forDomain($tab->domain);

    if ($legos === []) {
        // No legos for this domain yet -- nothing to classify against.
        // The GeneratorAgent is invoked separately via user intent, not the stream pipeline.
        return;
    }

    $domElements = self::extractDomElements($batch);

    if ($domElements === []) {
        return;
    }

    $policy = $policyStore->forDomain($tab->domain);
    $policyRules = $policy->rules !== [] ? array_map(
        static fn(\AgentBridge\Policy\PolicyRule $r) => $r->toArray(),
        $policy->rules,
    ) : null;

    $classifier = new ClassifierAgent(
        availableLegos: $legos,
        domElements: $domElements,
        policyRules: $policyRules,
    );

    $result = $tab->scope->execute($classifier);

    if ($result instanceof \Phalanx\Ai\AgentResult && $result->text !== '') {
        $classificationData = json_decode($result->text, true, 512, JSON_THROW_ON_ERROR);
        self::executeClassifications($tab, $classificationData, $legos, $legoLibrary);
    }
}

/** @param list<BridgeMessage> $batch */
private static function extractDomElements(array $batch): array
{
    $elements = [];

    foreach ($batch as $msg) {
        match ($msg->type) {
            'dom.snapshot' => $elements[] = [
                'type' => 'snapshot',
                'html' => $msg->payload['html'] ?? '',
                'selector' => $msg->payload['selector'] ?? '',
            ],
            'dom.mutations' => $elements = array_merge($elements, array_map(
                static fn(array $m) => ['type' => 'mutation', ...$m],
                $msg->payload['mutations'] ?? [],
            )),
            'net.response' => $elements[] = [
                'type' => 'network',
                'url' => $msg->payload['url'] ?? $msg->url ?? '',
                'status' => $msg->payload['status'] ?? 0,
                'contentType' => $msg->payload['contentType'] ?? '',
            ],
            default => null,
        };
    }

    return $elements;
}
```

### 3.5 Classification Result to LegoExecutor

The classifier returns a list of `{legoName, confidence}` pairs. The executor runs matching legos sequentially, one action at a time per tab.

```php
<?php

private static function executeClassifications(
    TabScope $tab,
    mixed $classificationData,
    array $legos,
    \AgentBridge\Lego\LegoLibrary $library,
): void {
    if (!is_array($classificationData)) {
        return;
    }

    $legoMap = [];
    foreach ($legos as $lego) {
        $legoMap[$lego->name] = $lego;
    }

    $executor = $tab->executor();

    foreach ($classificationData as $classification) {
        $name = $classification['legoName'] ?? null;
        if ($name === null || !isset($legoMap[$name])) {
            continue;
        }

        $lego = $legoMap[$name];

        // Skip low-confidence classifications
        if (($classification['confidence'] ?? 0.0) < 0.7) {
            continue;
        }

        $succeeded = $executor->execute($lego, $library);

        if (!$succeeded && $lego->failures >= 3) {
            self::requestRepair($tab, $lego);
        }

        // Update confidence in side panel
        $tab->session->send(\AgentBridge\BridgeCommand::uiUpdate('confidence', [
            'tabId' => $tab->tabId,
            'action' => $lego->name,
            'confidence' => $lego->confidence,
            'executions' => $lego->executions + 1,
            'overrides' => $lego->overrides,
        ]));
    }
}
```

### 3.6 Pipeline Lifecycle

**Creation:** `TabScope::startPipeline()` is called immediately after `TabScope` construction inside `TabManager::connectTab()`. The pipeline runs in a child fiber spawned by `$this->scope->execute()`.

**Disposal:** When `TabScope::dispose()` is called:

1. `$this->inbound->complete()` completes the inbound Channel.
2. The `Emitter::produce` generator's `$inbound->consume()` loop returns (Channel is complete).
3. The Emitter's Channel is completed, propagating through all downstream operators.
4. Each operator's `async()` fiber sees the upstream complete and calls `$ch->complete()` on its own Channel.
5. The `ScopedStream::consume()` terminal returns.
6. `$this->cancellation->cancel()` fires the CancellationToken, which causes any in-flight `$scope->await()` calls (including the classifier's AI request) to throw `CancelledException`.
7. `$this->scope->dispose()` runs all `onDispose` callbacks registered on the scope.

The pipeline tears down from the source (Channel completion) and from the token (CancellationToken cancellation) simultaneously. The Channel completion path handles the graceful case. The CancellationToken handles the case where the classifier is mid-AI-call and the tab disconnects.

### 3.7 Backpressure Integration

The TabScope's inbound Channel (bufferSize: 64) has a `withPressure` callback that sends `flow.throttle` / `flow.resume` to the extension. This connects the daemon's internal backpressure to the extension's observation rate.

```php
<?php

// Inside TabScope constructor, after Channel creation:

$session = $this->session;
$tabId = $this->tabId;
$config = $this->scope->service(BridgeConfig::class);
$throttledRate = $config->maxEventsPerSecThrottled;

$this->inbound->withPressure(static function (bool $paused) use ($session, $tabId, $throttledRate): void {
    if ($paused) {
        $session->send(\AgentBridge\BridgeCommand::throttle($tabId, maxEventsPerSec: $throttledRate));
    } else {
        $session->send(\AgentBridge\BridgeCommand::resume($tabId));
    }
});
```

Channel hysteresis: pauses producer (suspends the emitting fiber) when buffer reaches 64 items, resumes when buffer drains to 32 items (50%). The `withPressure` callback fires at the same thresholds, sending throttle/resume commands to the extension so it reduces observation frequency before TCP-level backpressure kicks in.

---

## 4. AI Agent Tool Definitions

Each tool is an invokable class implementing `Phalanx\Ai\Tool\Tool`. The Tool interface requires `public string $description { get; }` and extends `Scopeable` (the tool's `__invoke(Scope $scope)` runs within the agent loop's execution scope). Constructor parameters become the tool's input schema via `SchemaGenerator` -- parameter names map to JSON property names, types map to JSON Schema types, and `#[Param]` attributes provide descriptions.

### 4.1 ClassifyElements

Called by `ClassifierAgent`. Receives the DOM element list and available lego names. Returns classification decisions.

```php
<?php

declare(strict_types=1);

namespace AgentBridge\Agent;

use Phalanx\Ai\Tool\Param;
use Phalanx\Ai\Tool\Tool;
use Phalanx\Ai\Tool\ToolOutcome;
use Phalanx\Scope;

final class ClassifyElements implements Tool
{
    public string $description {
        get => 'Classify DOM elements against available legos. Return a list of classifications, each mapping a lego name to the elements it should act on.';
    }

    /**
     * @param list<array{legoName: string, confidence: float, elementIndices: list<int>}> $classifications
     */
    public function __construct(
        #[Param('Array of classification objects. Each has legoName (string), confidence (float 0-1), and elementIndices (array of int indices into the DOM elements list).')]
        public readonly array $classifications,
    ) {}

    public function __invoke(Scope $scope): ToolOutcome
    {
        // Validate classifications before returning
        $valid = array_filter($this->classifications, static function (array $c): bool {
            return isset($c['legoName'], $c['confidence'])
                && is_string($c['legoName'])
                && is_float($c['confidence']) || is_int($c['confidence']);
        });

        return ToolOutcome::done(array_values($valid));
    }
}
```

**Input schema** (generated by `SchemaGenerator` from constructor):

```json
{
    "name": "classify_elements",
    "description": "Classify DOM elements against available legos...",
    "input_schema": {
        "type": "object",
        "properties": {
            "classifications": {
                "type": "array",
                "description": "Array of classification objects..."
            }
        },
        "required": ["classifications"]
    }
}
```

**Output:** `ToolOutcome::done($data)` with `Disposition::Terminate`. This terminates the agent loop and returns the classification array as `AgentResult::$data`. The classifier is a single-turn agent -- it classifies and exits.

### 4.2 CreateLegos

Called by `GeneratorAgent`. Receives the AI's proposed lego definitions for a new site.

```php
<?php

declare(strict_types=1);

namespace AgentBridge\Agent;

use Phalanx\Ai\Tool\Param;
use Phalanx\Ai\Tool\Tool;
use Phalanx\Ai\Tool\ToolOutcome;
use Phalanx\Scope;

final class CreateLegos implements Tool
{
    public string $description {
        get => 'Submit generated lego definitions for a website. Each lego is a named, reusable action sequence with CSS selector targets.';
    }

    /**
     * @param list<array{name: string, description: string, steps: list<array{op: string, selector?: string, value?: string, timeoutMs?: int}>}> $legos
     */
    public function __construct(
        #[Param('Array of lego definitions. Each has name (string), description (string), and steps (array of step objects with op, selector, value, timeoutMs fields).')]
        public readonly array $legos,
    ) {}

    public function __invoke(Scope $scope): ToolOutcome
    {
        $validated = [];

        foreach ($this->legos as $lego) {
            if (!isset($lego['name'], $lego['steps']) || !is_array($lego['steps'])) {
                continue;
            }

            $validSteps = array_filter($lego['steps'], static function (array $step): bool {
                $validOps = [
                    'click', 'clickAll', 'type', 'fill', 'select', 'check', 'press',
                    'scroll', 'waitForSelector', 'waitForRemoval', 'waitForText',
                    'waitForNetwork', 'getAttribute', 'getTextContent', 'evaluate', 'delay',
                ];
                return isset($step['op']) && in_array($step['op'], $validOps, true);
            });

            if ($validSteps === []) {
                continue;
            }

            $validated[] = [
                'name' => $lego['name'],
                'description' => $lego['description'] ?? '',
                'steps' => array_values($validSteps),
            ];
        }

        return ToolOutcome::done($validated);
    }
}
```

**Output:** `ToolOutcome::done($data)` terminates the agent loop. The `GeneratorAgent`'s caller receives the validated lego definitions, constructs `LegoDefinition` objects, and saves them via `LegoLibrary::save()`.

### 4.3 RepairLego

Called by `RepairAgent`. Receives repaired steps with updated selectors for a broken lego.

```php
<?php

declare(strict_types=1);

namespace AgentBridge\Agent;

use Phalanx\Ai\Tool\Param;
use Phalanx\Ai\Tool\Tool;
use Phalanx\Ai\Tool\ToolOutcome;
use Phalanx\Scope;

final class RepairLego implements Tool
{
    public string $description {
        get => 'Submit repaired steps for a broken lego. The steps should use updated CSS selectors that match the current DOM structure.';
    }

    /**
     * @param list<array{op: string, selector?: string, value?: string, timeoutMs?: int}> $steps
     */
    public function __construct(
        #[Param('Array of repaired step objects. Same format as original lego steps but with corrected selectors.')]
        public readonly array $steps,
    ) {}

    public function __invoke(Scope $scope): ToolOutcome
    {
        if ($this->steps === []) {
            return ToolOutcome::retry('No steps provided. Analyze the DOM and provide repaired steps.');
        }

        return ToolOutcome::done($this->steps);
    }
}
```

**Output:** Returns `ToolOutcome::done($steps)` on success, `ToolOutcome::retry($hint)` if the AI returned empty steps (gives the AI another attempt with guidance). The `RepairAgent`'s caller uses the repaired steps to call `LegoDefinition::withRepairedSteps()`.

### 4.4 ValidateSelector

Called by `GeneratorAgent` during lego creation. Checks whether a proposed CSS selector matches elements in the current DOM. This tool does I/O -- it sends a `dom.request` to the content script and awaits the response.

```php
<?php

declare(strict_types=1);

namespace AgentBridge\Agent;

use AgentBridge\Tab\TabScope;
use Phalanx\Ai\Tool\Param;
use Phalanx\Ai\Tool\Tool;
use Phalanx\Ai\Tool\ToolOutcome;
use Phalanx\Scope;

final class ValidateSelector implements Tool
{
    public string $description {
        get => 'Validate a CSS selector against the live DOM. Returns how many elements match and whether the selector is likely stable (uses data attributes, aria labels, or role attributes rather than generated class names).';
    }

    public function __construct(
        #[Param('CSS selector to validate against the live DOM')]
        public readonly string $selector,
    ) {}

    public function __invoke(Scope $scope): ToolOutcome
    {
        $tabScope = $scope->attribute('tabScope');

        if (!$tabScope instanceof TabScope) {
            return ToolOutcome::data([
                'matchCount' => 0,
                'stable' => false,
                'error' => 'No tab context available for DOM validation',
            ]);
        }

        try {
            $elements = $tabScope->queryDom($this->selector, limit: 100);

            $stable = self::assessStability($this->selector);

            return ToolOutcome::data([
                'matchCount' => count($elements),
                'stable' => $stable,
                'sampleAttributes' => array_slice($elements, 0, 3),
            ]);
        } catch (\Throwable $e) {
            return ToolOutcome::data([
                'matchCount' => 0,
                'stable' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function assessStability(string $selector): bool
    {
        // Selectors using data attributes, aria labels, or role are considered stable.
        // Selectors using only class names with hashes or hex sequences are fragile.
        $stablePatterns = [
            '/\[data-/',
            '/\[aria-/',
            '/\[role=/',
            '/#[a-zA-Z]/',  // ID selectors (typically stable)
        ];

        foreach ($stablePatterns as $pattern) {
            if (preg_match($pattern, $selector)) {
                return true;
            }
        }

        // Fragile: class names that look auto-generated
        if (preg_match('/\.[a-z]{1,3}[A-Z][a-zA-Z0-9]{4,}/', $selector)) {
            return false;
        }
        if (preg_match('/\.[a-f0-9]{6,}/', $selector)) {
            return false;
        }

        return true; // Default: assume stable
    }
}
```

**Output:** `ToolOutcome::data(...)` with `Disposition::Continue`. This does NOT terminate the agent loop -- the AI receives the validation result and can call `validate_selector` on additional selectors before calling `create_legos` to submit the final lego definitions.

**Scope attribute threading:** The `GeneratorAgent`'s execution scope must carry `tabScope` as an attribute so `ValidateSelector` can access the live tab:

```php
<?php

// In the caller that invokes GeneratorAgent:
$generatorScope = $scope->withAttribute('tabScope', $tabScope);
$result = $generatorScope->execute($generatorAgent);
```

### 4.5 Tool Registration

Each agent's `tools()` method returns the tool classes. The `ToolRegistry` hydrates them from the AI's tool call arguments via constructor injection. No manual registration needed beyond the agent definition.

```
ClassifierAgent::tools()  -> [ClassifyElements::class]
GeneratorAgent::tools()   -> [CreateLegos::class, ValidateSelector::class]
RepairAgent::tools()      -> [RepairLego::class]
```

The `SchemaGenerator` derives JSON Schema from constructor parameters:
- `string` -> `{"type": "string"}`
- `int` -> `{"type": "integer"}`
- `float` -> `{"type": "number"}`
- `bool` -> `{"type": "boolean"}`
- `array` -> `{"type": "array"}`
- `#[Param('...')]` attribute -> adds `"description"` field
- Optional parameters -> not included in `"required"` array, default value added

---

## 5. Message Routing Corrections

The product SPEC.md's `BridgeGateway::pump()` routes messages by type prefix. Two corrections are required:

### 5.1 dom.response Must Bypass the Stream Pipeline

The product spec routes all `dom.*` messages to `handleDomMessage`, which emits into `TabScope::$inbound`. This is correct for `dom.snapshot` and `dom.mutations` (stream events), but wrong for `dom.response` (a request-reply that must resolve a pending Deferred).

**Corrected routing in `BridgeGateway::pump()`:**

```php
<?php

match (true) {
    $msg->type === 'dom.response' => $tabManager->handleDomResponse($msg, $session),
    str_starts_with($msg->type, 'tab.') => $tabManager->handleTabMessage($msg, $session),
    str_starts_with($msg->type, 'dom.') => $tabManager->handleDomMessage($msg, $session),
    str_starts_with($msg->type, 'net.') => $tabManager->handleNetMessage($msg, $session),
    str_starts_with($msg->type, 'user.') => $tabManager->handleUserMessage($msg, $session),
    str_starts_with($msg->type, 'action.') => $tabManager->handleActionResult($msg, $session),
    str_starts_with($msg->type, 'flow.') => $tabManager->handleFlowControl($msg, $session),
    default => null,
};
```

The `dom.response` exact match MUST precede the `dom.*` prefix match. `handleDomResponse` resolves the Deferred in `$pendingActions` by `requestId`:

```php
<?php

public function handleDomResponse(BridgeMessage $msg, ExtensionSession $session): void
{
    $tab = $this->tabs[$msg->tabId] ?? null;
    if ($tab === null) {
        return;
    }

    $requestId = $msg->payload['requestId'] ?? null;
    if ($requestId === null) {
        return;
    }

    $deferred = $tab->pendingActions[$requestId] ?? null;
    if ($deferred === null) {
        return; // Already resolved or timed out
    }

    $deferred->resolve($msg->payload['elements'] ?? []);
}
```

Without this fix, `TabScope::queryDom()` hangs until timeout because `dom.response` enters the stream pipeline, gets filtered out, and the Deferred is never resolved. This breaks `ValidateSelector`, `requestRepair()`, and any future DOM query.

### 5.2 user.chat Handler

The product spec routes `user.*` to `handleUserAction`, which records policy actions. `user.chat` is a conversation message from the side panel, not a user action observation. It needs its own handler.

**Corrected routing:** The `user.*` match in the pump loop is renamed to `handleUserMessage`, which dispatches:

```php
<?php

public function handleUserMessage(BridgeMessage $msg, ExtensionSession $session): void
{
    match ($msg->type) {
        'user.action' => $this->tabs[$msg->tabId]?->handleUserAction($msg),
        'user.chat' => $this->tabs[$msg->tabId]?->handleUserChat($msg),
        default => null,
    };
}
```

`TabScope::handleUserChat()` invokes the `GeneratorAgent` with the user's text as intent:

```php
<?php

public function handleUserChat(BridgeMessage $msg): void
{
    $text = $msg->payload['text'] ?? '';
    if ($text === '') {
        return;
    }

    $this->say("Understood. Analyzing the page for: {$text}");

    $this->scope->execute(\Phalanx\Task\Task::of(
        static function (\Phalanx\ExecutionScope $s) use ($text): void {
            $tab = $s->attribute('tabScope');
            assert($tab instanceof self);

            try {
                $domElements = $tab->queryDom('body', limit: 500);
                $currentDom = json_encode($domElements, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $tab->say('Could not read the page. Is the tab still connected?');
                return;
            }

            $generator = new \AgentBridge\Agent\GeneratorAgent(
                domSnapshot: $currentDom,
                userIntent: $text,
                domain: $tab->domain,
            );

            $generatorScope = $s->withAttribute('tabScope', $tab);
            $result = $generatorScope->execute($generator);

            if ($result instanceof \Phalanx\Ai\AgentResult && $result->text !== '') {
                $legos = json_decode($result->text, true, 512, JSON_THROW_ON_ERROR);
                $library = $s->service(\AgentBridge\Lego\LegoLibrary::class);

                foreach ($legos as $legoData) {
                    $lego = \AgentBridge\Lego\LegoDefinition::fromArray([
                        ...$legoData,
                        'domain' => $tab->domain,
                    ]);
                    $library->save($lego);
                }

                $tab->say('Created ' . count($legos) . ' new action(s) for ' . $tab->domain . '.');
            } else {
                $tab->say('I could not generate actions for that intent. Try being more specific.');
            }
        }
    ));
}
```

---

## 6. Daemon-Side Failure Handling (Corrected)

This section maps the failure modes cataloged in `../integration/SPEC.md` Section 2 to the daemon's internal scope disposal, cancellation propagation, and stream pipeline cleanup.

### 5.1 Scope Tree

The daemon maintains a scope hierarchy that mirrors the connection/tab structure:

```
Application Scope (root, lives for process lifetime)
    |
    +-- WsScope (per WebSocket connection, created by WsConnectionHandler)
    |       |
    |       +-- [BridgeGateway pump fiber]
    |       |
    |       +-- TabScope child scope (per tab.connect)
    |       |       |
    |       |       +-- [Stream pipeline fiber]
    |       |       +-- [ClassifierAgent execution fiber]
    |       |       +-- [LegoExecutor action fiber]
    |       |       +-- [RepairAgent execution fiber] (if active)
    |       |
    |       +-- TabScope child scope (another tab)
    |               ...
    |
    +-- WsScope (another extension instance)
            ...
```

Each level has its own `CancellationToken`. When a parent scope is disposed, its token is cancelled, which cascades to all child scopes via `CancellationToken::composite()`.

### 5.2 WebSocket Drop (Integration Spec 2.1)

**Trigger:** Transport layer `close` or `error` event on the `DuplexStreamInterface`.

**Daemon-side cascade:**

1. `WsConnectionHandler` fires transport `close` handler:
   ```php
   $transport->on('close', static function () use ($conn, $scope): void {
       $conn->inbound->complete();
       $conn->outbound->complete();
       $scope->dispose();
   });
   ```

2. `$scope->dispose()` fires `onDispose` callbacks on the `WsScope`:
   - `BridgeGateway`'s dispose callback calls `$tabManager->unregisterSession($session)`.
   - `WsConnectionHandler`'s dispose callback cancels the ping timer, closes the connection, unregisters from gateway.

3. `TabManager::unregisterSession()` iterates `$session->ownedTabIds()` and calls `disconnectTab()` for each.

4. `TabScope::dispose()` per tab:
   - Rejects all entries in `$pendingActions` with `RuntimeException('Tab disconnected')`.
   - Clears `$pendingActions` (breaks hard references to Deferred objects).
   - Calls `$this->inbound->complete()` (completes the Channel, causing the stream pipeline's consume loop to exit).
   - Calls `$this->cancellation->cancel()` (fires CancellationToken callbacks, which interrupt any in-flight `$scope->await()` calls).
   - Calls `$this->scope->dispose()` (runs all `onDispose` callbacks registered on the child scope, including stream operator timer cancellations).

**Deferred rejection cascade:** Every `$scope->await($deferred->promise())` call in `TabScope::executeAction()` and `TabScope::queryDom()` races the promise against the scope's CancellationToken. When the token is cancelled, the await throws `CancelledException`. The `finally` block in `executeAction()` runs `unset($this->pendingActions[$actionId])`, preventing the orphaned Deferred from leaking.

### 5.3 Daemon Process Crash (Integration Spec 2.2)

No daemon-side handling (the daemon is the crashed party). On restart:

1. `writeLockfile()` detects stale lockfile, checks PID, overwrites.
2. All services start fresh. No in-memory state survives.
3. `LegoLibrary` and `PolicyStore` read from disk -- file-based state survives crashes.
4. The extension reconnects and resends `tab.connect` messages per the integration spec's reconnection protocol.

### 5.4 Action Timeout (Integration Spec 2.5)

The content script enforces step-level timeouts. The daemon enforces an overall action timeout.

**Daemon-side timeout implementation:**

```php
<?php

// Inside TabScope::executeAction():
public function executeAction(array $steps): array
{
    $actionId = 'act_' . $this->nextActionId++;
    $deferred = new Deferred();
    $this->pendingActions[$actionId] = $deferred;

    $this->session->send(BridgeCommand::executeAction($this->tabId, $actionId, $steps));

    try {
        // timeout() wraps the promise with a CancellationToken::timeout()
        return $this->scope->timeout(
            $this->scope->service(BridgeConfig::class)->actionTimeoutSeconds,
            \Phalanx\Task\Task::of(static fn(\Phalanx\ExecutionScope $s) => $s->await($deferred->promise())),
        );
    } catch (\Phalanx\Exception\TimeoutException $e) {
        // Send cancel to extension so content script stops executing
        $this->session->send(BridgeCommand::cancelAction($this->tabId, $actionId));
        throw $e;
    } finally {
        unset($this->pendingActions[$actionId]);
    }
}
```

When the timeout fires:
1. `$scope->timeout()` cancels the inner task's token, causing the Deferred await to throw `TimeoutException`.
2. The `catch` block sends `action.cancel` to the extension.
3. The `finally` block removes the pending action entry.
4. The caller (LegoExecutor or ClassifierAgent pipeline) catches the exception and records the failure.

### 5.5 Navigation During Action (Integration Spec 2.6)

**SPA navigation:** Content script survives. Current step may fail if target element was removed. `action.result` arrives with `success: false`. The Deferred in `$pendingActions` is rejected. Normal error flow.

**Full navigation:** Content script is destroyed. No `action.result` ever arrives.

1. Extension sends `tab.disconnect` then `tab.connect` with new URL.
2. `TabManager::disconnectTab()` disposes the old TabScope (rejecting the pending action Deferred).
3. `TabManager::connectTab()` creates a new TabScope for the same tabId.
4. The stream pipeline that originated the action receives the rejection. Whether to retry is a policy decision -- the pipeline does not auto-retry navigated-away actions.

### 5.6 Tab Disconnect During Classification

The ClassifierAgent is mid-AI-call when the tab disconnects.

1. `TabScope::dispose()` cancels the CancellationToken.
2. The AI provider's HTTP request is being awaited via `$scope->await()`, which races against the token.
3. `CancelledException` propagates up through `AgentLoop::run()`.
4. The `Emitter::produce` catch block captures the exception and calls `$ch->error($e)`.
5. The Channel error propagates to all downstream operators, terminating the pipeline.
6. No action is executed. No resources leak. The AI request may complete server-side, but the response is discarded.

### 5.7 RepairAgent Trigger

When `LegoExecutor::execute()` fails and the lego's failure count reaches 3 consecutive failures:

```php
<?php

private static function requestRepair(TabScope $tab, \AgentBridge\Lego\LegoDefinition $lego): void
{
    // Request a fresh DOM snapshot from the content script
    try {
        $domElements = $tab->queryDom('body', limit: 500);
        $currentDom = json_encode($domElements, JSON_THROW_ON_ERROR);
    } catch (\Throwable) {
        // Tab may have disconnected -- cannot repair without DOM access
        return;
    }

    $repairAgent = new \AgentBridge\Agent\RepairAgent(
        brokenLego: $lego,
        currentDom: $currentDom,
    );

    try {
        $result = $tab->scope->execute($repairAgent);

        if ($result instanceof \Phalanx\Ai\AgentResult && $result->text !== '') {
            $repairedSteps = json_decode($result->text, true, 512, JSON_THROW_ON_ERROR);
            $repairedLego = $lego->withRepairedSteps($repairedSteps);
            $tab->scope->service(\AgentBridge\Lego\LegoLibrary::class)->save($repairedLego);

            $tab->say("Repaired '{$lego->name}' -- updated selectors to match current page structure.");
        }
    } catch (\Throwable $e) {
        $tab->say("Failed to repair '{$lego->name}': {$e->getMessage()}");
    }
}
```

The repair runs in the TabScope's execution scope. If the tab disconnects during repair, the CancellationToken handles cleanup (same as Section 5.6).

**Verification note:** The RepairAgent should verify mutations via a follow-up `queryDom()` call after action execution, not trust `action.result` alone. `action.result` confirms the content script ran the steps without exceptions, but does not confirm the page state actually changed (e.g., a React controlled input may report success from ISOLATED world while the framework state is unchanged). A post-action DOM query against the expected result selector provides ground truth.

**`mainWorld` flag learning:** When the RepairAgent diagnoses a controlled-input failure (action succeeded but DOM state didn't change), it sets `mainWorld: true` on the failing `fill`/`type` step and saves the repaired lego. This flag is associated with the site/selector pattern in the domain's hint cache so future lego generation for the same site uses MAIN world for similar inputs from the start.

### 5.8 Deferred Without Canceller

The product spec's `TabScope::executeAction()` creates Deferreds without a canceller callback. This is acceptable because cleanup is handled by the `finally` block and `TabScope::dispose()`:

- The `finally` block always runs `unset($this->pendingActions[$actionId])`.
- `TabScope::dispose()` iterates remaining `$pendingActions` and rejects each.
- No orphaned Deferreds survive TabScope disposal.

A canceller callback on the Deferred would be redundant -- the scope's CancellationToken already handles cancellation, and the finally block handles cleanup.

---

## 7. Configuration Reference

All configuration flows through `$context` in `BridgeServiceBundle::services()`. Environment variables come from `$_SERVER + $_ENV` merged in the `Application::starting()` call in `bin/bridge`.

| Variable | Default | Type | Purpose |
|----------|---------|------|---------|
| `BRIDGE_DATA_DIR` | `$HOME/.phalanx` | string | Root directory for lockfile, legos, policies |
| `BRIDGE_PORT` | `9078` | int | WebSocket server listen port |
| `BRIDGE_ACTION_TIMEOUT` | `30.0` | float | Seconds before daemon cancels an in-flight action |
| `BRIDGE_CLASSIFIER_BUFFER_COUNT` | `20` | int | Max events per classifier batch |
| `BRIDGE_CLASSIFIER_BUFFER_SECONDS` | `2.0` | float | Max seconds before classifier batch flushes |
| `BRIDGE_THROTTLED_EVENTS_PER_SEC` | `5` | int | maxEventsPerSec value sent in flow.throttle when Channel backpressure engages |
| AI provider config | -- | -- | Registered by `AiServiceBundle`, see phalanx-athena docs |

The `.env` file at the daemon project root is the typical source:

```
BRIDGE_PORT=9078
BRIDGE_DATA_DIR=$HOME/.phalanx
AI_PROVIDER_FAST=ollama
AI_PROVIDER_DEFAULT=anthropic
ANTHROPIC_API_KEY=sk-ant-...
```

`getenv()`, `$_ENV`, and `$_SERVER` are never read inside service bundles or application code. The `$context` array is the single entry point for all external configuration.
