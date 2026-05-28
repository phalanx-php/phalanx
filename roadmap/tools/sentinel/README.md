# Sentinel (POC)

Multi-agent code review CLI on Phalanx.

See main repo: https://github.com/phalanx-php/phalanx

Type a message at the `+>` prompt to ask all agents a question. They answer concurrently and reference each other's findings to avoid duplication.

## How It Works

Sentinel runs three concurrent loops in a single PHP process -- no threads, no workers, no message queues:

- **File watcher** detects changes, debounces, and emits batches
- **Input reader** handles raw terminal input with readline-style editing
- **daemon8 poller** picks up cross-agent observations in real time (optional)

When a change arrives, every active agent reviews it in parallel through Phalanx's fiber-based concurrency:

```php
<?php
// Each agent runs in its own fiber -- no callbacks, no promises
foreach ($agents as $name => $agent) {
    $tasks[$name] = Task::of(
        static fn(ExecutionScope $s) => self::executeAndCollect($turn, $s, $name)
    );
}

$scope->concurrent($tasks);
// All agents complete → results rendered sequentially
```

File watching uses Phalanx's `Emitter`/`Channel` primitives -- the same pattern drives both the file watcher and the terminal input reader:

```php
<?php
// Emitter produces events; consumer iterates with backpressure
$fileChanges = ProjectWatcher::watch($projectRoot);

foreach ($fileChanges($scope) as $batch) {
    $coordinator->reviewChanges($batch, $scope);
}
```

## Agents Are Markdown Files

Each agent is a markdown file in `personas/`. The filename becomes the agent's lens; the `# heading` becomes its name; the `> blockquote` becomes its tagline.

```markdown
# Aegis

> Injection, auth flaws, data exposure, cryptographic weakness

You are a security-focused code reviewer. You think like an attacker.
Every change is a potential attack surface expansion...
```

Add a file, get a new reviewer. No code changes required.

### Presets

| Preset | Agents |
|--------|--------|
| `core` | architecture, security, performance |
| `php` | architecture, performance, security, phalanx |
| `react-native` | architecture, state, performance, security |
| `tv` | navigation, streaming, state, performance |
| `full` | all personas |

```bash
php bin/sentinel.php sentinel /path/to/project --preset php
```

Or pick individual agents interactively when you omit `--preset`.

## Real-Time Coordination with daemon8

Sentinel uses [daemon8](https://daemon8.ai) as its runtime observation layer. When agents find something, they broadcast it. Other agents -- even in separate terminal sessions on the same project -- pick up those findings and build on them instead of duplicating work.

daemon8 is a Rust-based runtime bridge that connects directly to Chrome via CDP. No browser extension. It gives your terminal direct access to the browser's internals:

- Execute JavaScript and read console output from the command line
- Take ephemeral screenshots without touching the browser
- Read and filter network requests in real time
- Emulate mobile viewports for responsive debugging
- Coordinate multiple agents through shared observation channels

Sentinel works without daemon8 (the bridge gracefully degrades), but cross-session coordination requires it. daemon8 is a separate tool at **$49/year** -- [daemon8.ai](https://daemon8.ai).

## Built on Phalanx

Sentinel is built on [Phalanx](https://github.com/phalanx-php/phalanx), an async coordination framework for PHP 8.4+ that separates what you want from how it runs. Fibers, event loops, and concurrency primitives disappear behind a clean API:

- `$scope->concurrent($tasks)` -- fan-out/fan-in with automatic fiber management
- `Phalanx\Grammata\Files` -- pooled async filesystem operations
- `Phalanx\Athena\Swarm\SwarmBus` -- multi-agent coordination via daemon8 SSE
- `Phalanx\Theatron\Surface` -- raw-mode terminal UI with widget regions
- `Emitter::produce()` / `Channel` -- backpressure-aware streaming
- `Task::of()` -- composable units of work with retry, timeout, and cancellation

Service wiring is three bundles in `bin/sentinel.php`:

```php
<?php
$app = Application::starting($context)
    ->providers(
        new AiServiceBundle(),         // Anthropic/OpenAI providers, SwarmBus, SwarmConfig
        new FilesystemServiceBundle(), // Files facade backed by FilePool
        new SentinelServiceBundle(),   // SentinelConfig, ConsoleRenderer
    )
    ->compile();
```

Phalanx is under active development. If async PHP without the ceremony interests you, take a look.

## Status

**Work in progress.** The raw CLI mode works. A terminal UI (split panels, real-time token streaming) is in development.

We'd genuinely appreciate people trying Sentinel out and sharing feedback -- what works, what breaks, what's missing. Open an issue or start a discussion on the repo.

## Requirements

- PHP 8.4+
- `fswatch` (`brew install fswatch` / `apt install fswatch`)
- An Anthropic API key (OpenAI support exists but Anthropic is the primary target)
- [daemon8](https://daemon8.ai) for cross-session agent coordination (optional, $49/year)
