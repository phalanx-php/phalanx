# bg-agents

Phalanx-native CLI for background specialist agents on daemon8.

See the main monorepo: https://github.com/phalanx-php/phalanx. The tool
talks to daemon8 over its public HTTP/SSE surface.

Each specialist has identity (system prompt + voice + scope) but no
conversation history. Every query rebuilds context from scratch:
identity prompt + live daemon8 observations + RAG retrieval + the user's
prompt. Specialist responses flow back into daemon8 as observations.
A bookkeeper specialist runs in the background to keep the stream tidy
and to evolve a high-quality long-term memory.

## Requirements

- PHP 8.4+
- A running daemon8 instance (default `http://localhost:8888`)
- One or more of `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `GEMINI_API_KEY`

## Install

```bash
composer install
```

## Quick start

```bash
# health check
bin/bg-agents diag

# one-shot ask
bin/bg-agents ask supervisor "what's our overall posture?"
bin/bg-agents ask @runtime "what's the resource ownership invariant?"

# REPL with team auto-started
bin/bg-agents
```

## Commands

| Verb | What it does |
|---|---|
| `bg-agents` | Open REPL and start the team (default). |
| `bg-agents diag` | Health-check daemon8 connection. |
| `bg-agents ask <name> "<query>"` | One-shot specialist query (resolves by name or `@addressing`). |
| `bg-agents team` | Start the team without the REPL (keeps running until killed). |
| `bg-agents status` | Show last team heartbeat from daemon8. |
| `bg-agents bookkeeper` | List pending bookkeeper issues. |
| `bg-agents memory [topic]` | Search long-term RAG memory. |

REPL accepts the same verbs without the `bg-agents` prefix, plus `help`,
`list`, `bk accept N`, `bk dismiss N`, `exit`.

## Configuration

All config flows through environment variables (Symfony Runtime `$context`).
See `.env.example` for the full list. Common ones:

| Var | Default | Use |
|---|---|---|
| `DAEMON8_URL` | `http://localhost:8888` | daemon8 base URL |
| `BG_AGENTS_PROJECT_ROOT` | `pwd` | project the team is paired with |
| `BG_AGENTS_SPECS_DIR` | `./specs` | where specialist `.md` files live |
| `BG_AGENTS_BOOKKEEPER_FAST` | unset | shorten bookkeeper intervals for testing |
| `ANTHROPIC_API_KEY` / `OPENAI_API_KEY` / `GEMINI_API_KEY` | unset | LLM providers |

## Specialist specs

Each `specs/*.md` is a Markdown file with YAML frontmatter:

```markdown
---
name: runtime
addressing: ["@runtime", "@platform"]
provider: anthropic
model: claude-sonnet-4-6
temperature: 0.3
description: constrained runtime specialist
subscription:
  kinds: [custom, log, exception]
  tags: [runtime, platform]
rag:
  tags: [bg.memory, runtime]
  topics: [resource-lifecycle, memory-pressure]
---
You are the runtime specialist...
```

The body is the identity prompt; everything above is structured config.

## Architecture

Five Phalanx packages do the heavy lifting:

- `phalanx-aegis` — scope hierarchy, DI, concurrency primitives
- `phalanx-archon` — CLI runner
- `phalanx-athena` — `Agent::quick()`, `Daemon8SwarmBus`, multi-provider config
- `phalanx-styx` — backpressure-aware streams
- `phalanx-grammata` — managed file I/O

bg-agents adds the specialist registry, context-pack assembly, three-lane
bookkeeper, REPL, and RAG memory layered on top of daemon8 observations.

The bookkeeper has three lanes:

1. **HygieneLane** (live) — fingerprints every swarm event and raises a
   duplicate issue when the same fingerprint repeats inside the window.
2. **ConsolidationLane** (every 5 min) — clusters recent observations,
   calls `gemini-2.0-flash` on noisy clusters, raises a structured
   `consolidation_proposed` issue.
3. **PromotionLane** (every 30 min) — drafts a `MemoryRecord` from
   related observations using `claude-sonnet-4-6`. Acceptance via
   `bk accept N` writes the record to RAG.

Memory is stored as daemon8 observations tagged `bg.memory`. There is no
local cache: daemon8 is the source of truth.

## Helper scripts

- `scripts/seed-fake-noise.php [count]` — pump fake observations to test
  the consolidation lane.
- `scripts/seed-memory.php "<topic>" "<summary>" [tags]` — write a
  memory record directly (skip the bookkeeper flow).

## Testing

```bash
composer analyse   # PHPStan level 8
composer test      # PHPUnit
```
