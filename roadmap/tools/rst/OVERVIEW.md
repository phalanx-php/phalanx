# phalanx-rst — Reasoning Syntax Tree

> A semantic indexing layer that reduces a codebase to a navigable graph — designed so AI
> agents can orient themselves before reading source files.

---

## The Problem

AI agents working on codebases currently have two modes:

1. **Read raw source files** — accurate, but token-expensive and structurally blind
2. **Tool-specific integrations** (Copilot context, Cursor indexing) — non-portable, closed, agent-specific

Neither is a protocol. Neither is language-agnostic. Neither gives an agent a way to ask
"what handles billing?" and get a precise, structured answer without reading files first.

The symptom surfaces predictably. An agent is asked to add a fallback payment gateway. It
greps for "stripe", gets 40 files, reads 25,000 tokens of PHP, misses the EventBus dependency,
and produces code that breaks the build. The agent wasn't wrong to try — it had no better option.

The root cause: there is no semantic map between the agent and the code. There is no layer
that says "this is what exists, this is what it does, this is what breaks if you change it"
without requiring the agent to discover all of that by reading implementation files.

---

## The Core Insight

Three tiers of information can be extracted from a codebase:

```
Reflection  →  what exists
                class graph, dependency edges, registered interfaces

AST         →  what happens
                data flow, control flow, mutation surface, call sequences

RST         →  what it means
                semantic pattern, side-effect profile, blast radius, SAGA boundaries
```

Reflection (PHP's `ReflectionClass`) gives topology. The PHP parser gives causality. The RST
gives *meaning* — the layer agents actually need to navigate and reason.

In a Phalanx codebase, the framework's own conventions are already semantic signals. A call
to `$scope->concurrent()` is a concurrency declaration. Implementing `Scopeable` is a
scope-width declaration. `SelfDescribed::$description` is a human-readable semantic label.
The RST does not invent new metadata — it harvests what the framework already encodes, combines
it with what the parser can extract, and emits a queryable graph.

---

## The Vocabulary

The RST protocol uses exactly 8 verbs and 4 properties. Nothing else.

### Verbs

| verb      | what it means                                |
|-----------|----------------------------------------------|
| check     | test a condition — may stop with error       |
| read      | get data from a store or system              |
| write     | put data into a store or system              |
| call      | invoke another system, wait for response     |
| send      | emit to another system, don't wait           |
| transform | pure computation, no I/O                     |
| each      | apply steps to a collection                  |
| return    | produce output                               |

### Properties

| property | what it means                                |
|----------|----------------------------------------------|
| safe     | can repeat without consequence (idempotent)  |
| final    | cannot be undone (point of no return)        |
| slow     | involves waiting (async, I/O)                |
| maybe    | can fail, has an error path                  |

These 12 words fully describe the *profile* of any computation without describing its
implementation. Every piece of code expresses a transformation on state — these words capture
the transformation's safety, reversibility, I/O profile, and failure posture.

If a concept cannot be expressed with one of these 8 verbs and 4 properties, either the concept
should be two steps (one verb each), or the vocabulary needs exactly one new word added
deliberately. Resist drift.

---

## The Subsystem Model

The protocol distinguishes three layers that must not be conflated:

```
SUBSYSTEM DEFINITION    named once, stable, lives in semantic.graph.yaml
  payment-gateway:
    charge: { final: true, slow: true, maybe: true }
    refund: { slow: true, maybe: true }

SUBSYSTEM REFERENCE     used in steps — names a capability, not an implementation
  - call: payment-gateway.charge

IMPLEMENTATION BINDING  not in the protocol — lives in DI config / ServiceBundle
  payment-gateway → App\Service\StripeClient
```

Steps reference subsystem capability names. PHP class strings never appear in the semantic graph
except in a node's own identity. If the payment provider changes from Stripe to Braintree, no
step in any node changes. The subsystem definition is the semantic contract; the implementation
binding is a project concern.

This is the same principle that makes OpenAPI useful: the spec describes capability, not
implementation. A step that says `call payment-gateway.charge` is stable across ORM swaps,
refactors, and provider migrations.

---

## The Protocol in Practice

### Easy: `get-user`

```yaml
nodes:
  get-user:
    reads: [user-store]
    steps:
      - check:  user-id present
      - read:   user-store
      - return: user
```

Three steps. Two failure paths implied by `check` and `read` defaults. Safe to retry. Any
developer in any language reads this and understands the complete logic without opening a file.

---

### Medium: `create-subscription`

```yaml
nodes:
  create-subscription:
    reads:  [user-store]
    writes: [user-store, payment-gateway]
    sends:  [event-bus]
    steps:
      - check:  payment-token valid
      - check:  no active subscription
      - read:   user-store.read
      - call:   payment-gateway.charge       # inherits: final, slow, maybe
      - write:  user-store.write
        compensate: payment-gateway.refund   # SAGA path: if DB write fails after charge
      - send:   event-bus.publish
        event:  subscription-created
      - return: subscription
```

The `call: payment-gateway.charge` step inherits `final: true` from the subsystem definition.
That makes it the **commitment boundary** — the point after which the operation cannot be cleanly
aborted. Everything before it is free to fail; everything after it is a SAGA. The `compensate`
field on the following write names the recovery action if that step fails after the charge succeeded.

An agent reading this knows the complete risk topology — what's reversible, where the point of
no return is, what the recovery path looks like — without opening the PHP file.

---

### Hard: `review-codebase`

```yaml
nodes:
  review-codebase:
    reads:  [filesystem, agent-pool]
    writes: [review-store]
    sends:  [notification-service]
    steps:
      - read:  filesystem.read
      - check: files within scope
      - each:  file in changed-files
        slow:  true
        steps:
          - call: agent-pool.architect
          - call: agent-pool.security
          - call: agent-pool.performance
          - transform: merge-reviews
      - transform: consolidate
      - write:  review-store.write
        safe:   true
      - send:   notification-service.publish
      - return: review-summary
```

The `each` block with three independent `call` steps marked `slow: true` tells an agent
they can be parallelized — no ordering constraint exists between them. The `safe: true`
override on the write means it's idempotent and safe to retry without concern. Fan-out
and reconvergence are visible without reading a line of implementation.

---

## What This Enables for Agents

The same task — adding a fallback payment gateway — looks like this with and without the RST.

**Without RST:**

1. Agent greps for "stripe" → 40 files returned
2. Agent reads `SubscriptionController.php`, `StripeService.php`, `SubscriptionModel.php`
3. Agent reads import statements to trace dependencies
4. Misses that `EventBus` is dispatched after the charge
5. Writes code that breaks the build
6. Total: ~25,000 tokens, one broken build

**With RST:**

1. `rst_explore_domain('billing')` → 3 nodes listed with their dependency surfaces (150 tokens)
2. `rst_inspect_node('create-subscription')` → full step list, sees the `event-bus.publish` (200 tokens)
3. `rst_blast_radius('payment-gateway')` → two nodes affected: `create-subscription`, `process-refund` (100 tokens)
4. Agent reads `CreateSubscription.php` — one file, full context already established
5. Writes the correct edit on the first attempt
6. Total: ~650 tokens, zero broken builds

The RST is not replacing file reads. It is sequencing them — providing a semantic compass so
the agent reads the right files, in the right order, with the right context already in place.

**Blast radius** is the highest-value tool for agents making changes. Before touching any
interface, the agent calls `rst_blast_radius('user-store')` and receives the complete list of
every node that reads or writes that subsystem. No guessing. No missed dependencies.

---

## The Universal Protocol Vision

The YAML schema is language-agnostic. The same node structure applies to any codebase that
has an RST emitter:

- Phalanx (PHP) — compiled by phalanx-rst
- Axum (Rust) — compiled by a hypothetical `cargo-rst` plugin
- Express (TypeScript) — compiled by a ts-morph transform
- ASP.NET Core (C#) — compiled by a Roslyn analyzer

The parallel to OpenAPI is exact. Before OpenAPI, developers read backend source code to
understand an API. OpenAPI defined `swagger.json` — a machine-readable semantic contract for
HTTP APIs. RST defines `semantic.graph.yaml` — the same thing for internal repository logic.

MCP standardized tool invocation across AI agents. RST standardizes code navigation across
codebases. The schema is the protocol. Any language that can emit `semantic.graph.yaml`
conforming to this schema becomes navigable by any agent that speaks RST — without the agent
knowing or caring what language the codebase is written in.

Phalanx's implementation is the reference: the first framework natively optimized for AI
navigation. The conventions that make Phalanx code clean to write are the same conventions
that make it tractable to compile into a semantic graph.

---

## Why Phalanx Is Uniquely Positioned

Most frameworks require heavy static analysis or ML inference to produce useful semantic metadata.
Phalanx requires neither, because the framework's design conventions carry the signal directly:

- **PSR-4 autoloading**: class-string is the file path. Bijective, O(1) lookup. No scanning needed.
- **Constructor injection**: all dependencies are visible via reflection. No guessing about what a handler uses.
- **Invokable-as-identity**: every meaningful computation is a named class. Every class has a stable identifier.
- **`SelfDescribed`, `Tagged`, `Traceable`**: RST annotation interfaces already in `phalanx-core`. Every invokable that implements them is a pre-annotated RST node.
- **Framework primitives as semantic signals**: `$scope->concurrent()` declares concurrency. `$scope->race()` declares a race. `$scope->defer()` declares fire-and-forget. The API calls name the pattern — the compiler recognizes them without heuristics.
- **`__invoke()` discipline** (15-line maximum): keeps extraction tractable. The method body is a table of contents, not an implementation.
- **ServiceGraph**: already a compiled partial RST at runtime. Services, aliases, lifecycle metadata — the service layer of the graph exists for free.

Other frameworks can produce RST-compatible output. Phalanx produces it with significantly less
inference work because the conventions already encode the semantics. The extraction is closer
to transcription than analysis.

---

## Intellectual Lineage

These ideas are not new. The RST applies existing theory to a practical problem.

**Hoare triples** (Tony Hoare, 1969): the foundational insight that every computation can be
described as `{precondition} operation {postcondition}`. The RST step schema is a practical
Hoare triple — `check` is the precondition enforcement, the verb is the operation, `maybe` and
`compensate` are the postcondition handling — without the formal notation.

**Effect systems** (type theory): languages like Haskell encode side effects in types (`IO`,
`State`, `Maybe`, `Either`). A function's type signature tells you its complete effect profile
without reading its body. PHP has no effect types, so RST constructs the equivalent as metadata.
The 4 properties (`safe`, `final`, `slow`, `maybe`) are the effect type vocabulary.

**Semantic role theory** (Charles Fillmore, 1968): all sentences in all human languages have
participants playing universal roles (agent, patient, source, goal) regardless of surface
grammar. RST applies the same insight to computation: all operations in all languages have
participants playing universal roles. The 8 verbs are the computational analog of semantic roles.

**OpenAPI**: the direct practical ancestor. RST is to internal codebase logic what OpenAPI
is to HTTP APIs. The lesson from OpenAPI: a machine-readable semantic contract reduces the
cognitive load of working with a system — for humans and for agents.
