# phalanx-rst Specification

**Version:**   0.1.0-draft
**PHP:**       8.4+
**Status:**    Draft
**Namespace:** `Phalanx\Rst\`
**Package:**   `phalanx/rst`

---

## 1. YAML Schema

### 1.1 Top-Level Structure

```yaml
version: "1.0"       # required
subsystems: { ... }  # named capability registries
nodes: { ... }       # semantic node map
```

### 1.2 Subsystem Definition

Subsystems are named registries of capabilities. Each capability carries default property
values that are inherited by any step referencing that capability.

```yaml
subsystems:
  <subsystem-name>:
    <capability-name>:
      safe:  bool   # default: false
      final: bool   # default: false
      slow:  bool   # default: false
      maybe: bool   # default: false
```

Full example:

```yaml
subsystems:
  user-store:
    read:   { safe: true }
    write:  { maybe: true }
    delete: { final: true, maybe: true }

  payment-gateway:
    charge: { final: true, slow: true, maybe: true }
    refund: { slow: true, maybe: true }

  event-bus:
    publish: { safe: true }

  filesystem:
    read:  { safe: true, slow: true }
    write: { maybe: true, slow: true }
    watch: { slow: true }

  agent-pool:
    architect:   { slow: true, maybe: true }
    security:    { slow: true, maybe: true }
    performance: { slow: true, maybe: true }
```

### 1.3 Node Definition

```yaml
nodes:
  <node-id>:               # kebab-case; derived from class name by compiler
    reads:  [<subsystem>]  # subsystems this node reads from (for blast radius)
    writes: [<subsystem>]  # subsystems this node writes to
    sends:  [<subsystem>]  # subsystems this node sends to (fire-and-forget)
    steps:  [...]          # ordered step list
```

The `reads`, `writes`, and `sends` arrays summarise the node's subsystem surface. They are
the primary data used by `rst_blast_radius` — the compiler populates them automatically from
the classified step list. Developers should not write them by hand; they are emitted output.

### 1.4 Step Structure

Each step has exactly one verb key. All other keys are optional property overrides or
step-specific fields.

```yaml
# Verb forms
- check:     <description>            # guard clause — maybe implied
- read:      <subsystem[.capability]> # fetch from store or system
- write:     <subsystem[.capability]> # put to store or system
- call:      <subsystem.capability>   # invoke external system, await response
- send:      <subsystem[.capability]> # emit, no await
- transform: <description>            # pure computation, no I/O
- each:      <description>            # iteration or fan-out
- return:    <type-name>              # terminal step
- node:      <node-id>               # cross-node reference (not a computation verb)

# Optional fields (any step)
  safe:       bool
  final:      bool
  slow:       bool
  maybe:      bool
            | { error: <http-status> }
            | { message: <string> }
            | { continue: true }       # for steps inside each — log and continue
  compensate: <subsystem.capability>
            | <node-id>               # compensating action if this step fails
  event:      <event-name>            # for send steps

# each-specific
  steps: [...]                        # nested step list (required for each)
```

### 1.5 Property Inheritance Rules

1. Capability properties in the `subsystems` section are the **baseline defaults**.
2. Step-level properties **override** capability defaults when present.
3. `safe: true` is always overridable — some writes are idempotent by design.
4. `final: true` on a step overrides a `false` default from the subsystem (opt-in irreversibility).
5. `final: false` on a step does NOT override a `true` subsystem default. If the subsystem
   says a capability is irreversible, that cannot be relaxed at the step level. Reconsider
   the subsystem definition instead.

### 1.6 Cross-Node References

When a step delegates its full execution to another defined node, use `node:`:

```yaml
steps:
  - node:  validate-payment-token
    maybe: { error: 422 }
  - read:  user-store.read
```

The `node:` verb is a stable reference to the node ID, not the PHP class. If
`ValidatePaymentToken` is refactored or renamed, only the node definition changes — all
`node: validate-payment-token` references remain valid.

### 1.7 Commitment Boundary

The first step in a node with `final: true` (from subsystem inheritance or step override)
is the **commitment boundary**. After this point, the operation cannot be cleanly aborted.

The compiler detects and annotates this automatically. Any `write` or `call` step that
appears after the commitment boundary AND carries `maybe: true` SHOULD have a `compensate`
field. The compiler emits a warning when this is missing; it is not a hard error, because
some operations are intentionally partially-compensated (the developer chose not to undo
earlier steps).

```yaml
steps:
  - check:  payment-token valid           # abort on fail — before boundary
  - read:   user-store.read               # abort on fail — before boundary
  - call:   payment-gateway.charge        # final: true → COMMITMENT BOUNDARY
  - write:  user-store.write              # after boundary — should have compensate
    compensate: payment-gateway.refund
  - send:   event-bus.publish             # fire-and-forget — no compensate needed
```

---

## 2. Vocabulary Reference

### 2.1 Verb Taxonomy

| Verb      | PHP patterns captured                                   | Default properties      |
|-----------|---------------------------------------------------------|-------------------------|
| check     | guard `if/throw`, early `return`, precondition          | maybe: true (implied)   |
| read      | find, get, fetch, load, query, look up                  | inherited from subsystem|
| write     | save, create, update, delete, persist, store, insert    | inherited from subsystem|
| call      | HTTP client, external RPC, synchronous API call         | slow: true, maybe: true |
| send      | event dispatch, queue push, email, webhook              | safe: true              |
| transform | pure computation, format, calculate, parse, map data    | none                    |
| each      | foreach, map, concurrent fan-out, collection processing | none                    |
| return    | return statement, response production                   | none                    |

`node:` is a cross-reference verb, not a computation verb. It carries no default properties.

### 2.2 Phalanx Primitive → RST Mapping

| Phalanx API call                   | RST representation                            |
|------------------------------------|-----------------------------------------------|
| `$scope->concurrent(...)`          | `each` + nested steps + `slow: true`          |
| `$scope->map($items, $fn)`         | `each` + nested steps + `slow: true`          |
| `$scope->race(...)`                | `each` + nested steps + `race: true`          |
| `$scope->series(...)`              | sequential steps (no special wrapper needed)  |
| `$scope->retry($task, $policy)`    | step with `safe: true`                        |
| `$scope->timeout($secs, $task)`    | step with `slow: true`                        |
| `$scope->defer($task)`             | `send` (fire-and-forget)                      |
| `$scope->service(X::class)->m()`   | `read` or `write` or `call` depending on `m` |
| event `dispatch(new FooEvent())`   | `send`                                        |

### 2.3 The `compensate` Field

`compensate` names the action taken if a step fails after a commitment boundary has been
crossed. It is a SAGA pointer — not automatic retry, not rollback, but an explicit compensating
transaction.

Values:
- `<subsystem.capability>` — call a specific subsystem capability (e.g. `payment-gateway.refund`)
- `<node-id>` — execute another defined RST node as the compensation

The compensating action is a semantic declaration in the graph, not executable code. The
compiler records it; the MCP server surfaces it; the actual compensation logic lives in the
application code.

---

## 3. Compiler Pipeline

Invoked via: `php bin/ace compile`

Six stages, each a Phalanx `Executable`. The pipeline runs concurrently where stages are independent:

```
[1] DiscoverNodes        PSR-4 classmap → Scopeable/Executable class list
         ↓
[2] ExtractSteps         nikic/php-parser NodeVisitor → RawStepDraft[] per class
         ↓  (concurrent, limit: 20)
[3] ResolveSubsystems    TypeTracker + SubsystemRegistry → subsystem refs resolved
         ↓  (concurrent, limit: 20)
[4] ClassifySteps        VerbClassifier + property inheritance → ClassifiedStep[]
         ↓  (concurrent, limit: 20)
[5] LinkNodes            cross-node refs + commitment boundary detection
         ↓
[6] EmitGraph            → .phalanx/semantic.graph.yaml + .phalanx/semantic.graph.json
```

```php
<?php

final class CompileGraph implements Executable
{
    public function __construct(
        private readonly SourceRoot $sourceRoot,
        private readonly SubsystemRegistry $registry,
        private readonly VerbClassifier $classifier,
    ) {}

    public function __invoke(ExecutionScope $scope): SemanticGraph
    {
        $classes  = $scope->execute(new DiscoverNodes($this->sourceRoot));
        $drafts   = $scope->map($classes,  static fn($c) => new ExtractSteps($c),                    limit: 20);
        $resolved = $scope->map($drafts,   static fn($d) => new ResolveSubsystems($d, $this->registry), limit: 20);
        $steps    = $scope->map($resolved, static fn($r) => new ClassifySteps($r, $this->classifier),   limit: 20);
        $linked   = $scope->execute(new LinkNodes($steps));

        return $scope->execute(new EmitGraph($linked));
    }
}
```

### Stage 1: DiscoverNodes

**Input:** source root path
**Output:** `list<class-string>`

- Read `vendor/composer/autoload_classmap.php`
- Filter entries to the configured source namespace(s) (from `ace.yaml`)
- Reflect each candidate: keep if `implements Scopeable` or `implements Executable`
- Also include classes registered in any `HandlerGroup` found via `HandlerLoader::loadDirectory()`
- Exclude abstract classes and interfaces

### Stage 2: ExtractSteps

**Input:** `class-string`
**Output:** `RawStepDraft[]` (unclassified, subsystem refs unresolved)

Uses `nikic/php-parser` with a custom `InvokeBodyVisitor` that walks the `__invoke()` method body.

**The visitor emits raw steps for:**

- `MethodCall` on `$this->property` → raw step with `(propertyVar, methodName, argHints)`
- `MethodCall` on `$scope` → Phalanx primitive detection (see §2.2)
- `If_` node where EVERY branch ends in `throw` or early `return` → `check` step
- `If_` node where the positive branch continues execution → NOT emitted (inline logic)
- `Return_` → `return` step with return type from docblock or type hint
- `Foreach_` / loop containing `$scope->map` or `$scope->concurrent` → `each` with nested extraction
- `New_` for a class that `implements Scopeable|Executable` → candidate for `node:` reference

**The visitor deliberately ignores:**

- Variable assignments not involving service method calls
- Pure arithmetic, string concatenation, array operations with no service calls
- `$scope->throwIfCancelled()` and `$scope->isCancelled` (infrastructure guards, not steps)
- PHPDoc comments and attributes (read separately by the attribute merge pass)
- Constructor body (dependencies declared there are handled by TypeTracker, not the visitor)

**`check` classification rule:** a raw step is classified as `check` when the `If_` node's
all-branches analysis confirms every exit path is a `throw` or `return`. If any branch
continues to further statements, the `If_` represents conditional logic, not a guard, and
is not emitted as a step.

### Stage 3: ResolveSubsystems

**Input:** `RawStepDraft[]` + `SubsystemRegistry`
**Output:** `ResolvedStepDraft[]`

For each raw step carrying an `(objectVar, methodName)` pair:

1. `TypeTracker::typeOf($propertyVar)` → fully-qualified class name
2. Special case: `$scope->service(X::class)` → extract `X::class` from the argument node
3. `SubsystemRegistry::lookup($fqcn)` → subsystem name, or `null`
4. If `null`: mark step as `unresolved`, emit a compiler warning, continue (not a failure)

**TypeTracker** resolves `$this->property` types via constructor reflection:

```php
<?php

final class TypeTracker
{
    /** @var array<string, string> propertyName → FQCN */
    private array $map = [];

    public static function fromClass(string $class): self
    {
        $self = new self();

        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $param) {
            $type = $param->getType()?->getName();
            if ($type !== null) {
                $self->map[$param->getName()] = $type;
            }
        }

        return $self;
    }

    public function typeOf(string $propertyName): ?string
    {
        return $this->map[$propertyName] ?? null;
    }
}
```

TypeTracker covers the dominant pattern in Phalanx handlers: all dependencies are
constructor-injected and available via reflection without AST traversal. Local variable
types (assigned mid-method) are outside TypeTracker's scope; those steps are emitted as
`unresolved` with a warning.

### Stage 4: ClassifySteps

**Input:** `ResolvedStepDraft[]`
**Output:** `ClassifiedStep[]`

**VerbClassifier** maps method names to verbs using prefix matching:

| Method name prefix(es)                        | Assigned verb |
|-----------------------------------------------|---------------|
| find, get, fetch, load, read, lookup, retrieve | read          |
| save, create, insert, persist, store           | write         |
| update, modify, set, patch, replace            | write         |
| delete, remove, destroy, drop, purge           | write         |
| charge, pay, process, execute (on gateway)     | call          |
| send, push, publish, dispatch, emit, notify    | send          |
| validate, verify, check, assert, ensure        | check         |
| calculate, compute, transform, format, convert | transform     |

After verb assignment, properties are resolved:
1. Look up the subsystem capability definition for the resolved `subsystem.capability`
2. Apply capability defaults as the property baseline
3. Apply any step-level overrides from `#[Effect]` attributes (see §5)
4. Final property set is written to `ClassifiedStep`

Steps that remain `unresolved` after Stage 3 are assigned verb `call` with properties
`{ slow: true, maybe: true }` as a conservative default, and a `warning` field in the output.

### Stage 5: LinkNodes

**Input:** `ClassifiedStep[]` for ALL nodes (the full graph is needed for cross-references)
**Output:** `LinkedNode[]`

**Cross-node reference detection:**
- Any `New_` AST node for a type that `implements Scopeable|Executable` AND exists in the
  discovered node list from Stage 1 → emit as `node: <node-id>` step
- The node ID is derived from the class name: strip namespace, PascalCase → kebab-case
- If the `#[NodeId('custom-id')]` attribute is present, use that instead

**Commitment boundary detection:**
- Walk each node's step list in order
- First step where the resolved `final` property is `true` → mark as commitment boundary
- Record the step index; used by the MCP server's blast-radius response and by the emitter

**SAGA validation:**
- For each step after the commitment boundary with `maybe: true`
- If no `compensate` field is present → emit a compiler warning (not an error)
- Message: `"Step N in node '<id>' is fallible after the commitment boundary but has no compensate field"`

### Stage 6: EmitGraph

**Input:** `LinkedNode[]`
**Output:** `.phalanx/semantic.graph.yaml`, `.phalanx/semantic.graph.json`

The emitted file includes:
- `version` field
- All subsystem definitions (from the SubsystemRegistry)
- All linked nodes with classified steps
- Compiler metadata block:

```yaml
_meta:
  generated_at: "2026-04-14T09:30:00Z"
  compiler_version: "0.1.0"
  source_hash: "sha256:..."   # hash of all source files processed
  warnings: 3                 # count of unresolved steps or missing compensate fields
```

---

## 4. SubsystemRegistry

```php
<?php

final class SubsystemRegistry
{
    /** @param array<string, SubsystemDefinition> $definitions */
    private function __construct(
        private array $definitions,
        /** @var array<string, string> FQCN → subsystem name */
        private array $bindings,
    ) {}

    public static function fromYaml(string $path): self { ... }
    public static function fromServiceGraph(ServiceGraph $graph): self { ... }
    public static function empty(): self { ... }

    /** FQCN → subsystem name, or null if unbound */
    public function lookup(string $fqcn): ?string { ... }

    public function capability(string $subsystem, string $cap): ?CapabilityDefinition { ... }

    public function define(string $name, SubsystemDefinition $def): self { ... }

    public function bind(string $fqcn, string $subsystem): self { ... }

    public function merge(self $other): self { ... }
}
```

### Registry Resolution Order (highest priority first)

1. **`ace.yaml` manifest** — explicit bindings always win
2. **`#[Subsystem('name')]` attribute** — on the interface or concrete class
3. **Naming convention** — strip namespace, strip suffix (Repository/Service/Client/Gateway),
   PascalCase → kebab-case. `UserRepositoryInterface` → `user-repository`.
   Note: convention-derived names may not match manually-defined subsystem names. When
   a convention binding produces a name with no matching subsystem definition, a warning
   is emitted and the step is marked `unresolved`.

### Manifest Format (`.phalanx/ace.yaml`)

```yaml
# .phalanx/ace.yaml
subsystem-bindings:
  App\Repository\UserRepositoryInterface: user-store
  App\Repository\SubscriptionRepository:  user-store
  App\Service\StripeClient:               payment-gateway
  App\Service\BraintreeClient:            payment-gateway
  App\Messaging\EventBus:                 event-bus
  App\Storage\S3Client:                   file-store

subsystems:
  user-store:
    read:   { safe: true }
    write:  { maybe: true }
    delete: { final: true, maybe: true }

  payment-gateway:
    charge: { final: true, slow: true, maybe: true }
    refund: { slow: true, maybe: true }

  event-bus:
    publish: { safe: true }

  file-store:
    read:   { safe: true, slow: true }
    write:  { slow: true, maybe: true }
    delete: { final: true, maybe: true }
```

Multiple PHP types can bind to the same subsystem (Stripe and Braintree both bind to
`payment-gateway`). The subsystem definition is the semantic contract; the binding tells
the compiler which PHP types implement it.

---

## 5. The `#[Effect]` Attribute

The `#[Effect]` attribute is an escape hatch for steps the compiler cannot classify from
the AST and SubsystemRegistry alone. It annotates the class as a whole, not individual lines.

```php
<?php

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class Effect
{
    public function __construct(
        public string $entity,
        public string $op,
        public string $scope    = Scope::PROCESS,
        public string $reversible = Reversibility::COMPENSATABLE,
        public ?string $system  = null,
    ) {}
}
```

Constants:

```php
<?php

final class Op
{
    const string CREATE     = 'create';
    const string READ       = 'read';
    const string UPDATE     = 'update';
    const string DELETE     = 'delete';
    const string TRANSITION = 'transition';
}

final class Scope
{
    const string LOCAL    = 'local';    // no external state
    const string PROCESS  = 'process'; // internal store (DB, cache)
    const string EXTERNAL = 'external';// crosses trust boundary
}

final class Reversibility
{
    const string IDEMPOTENT    = 'idempotent';    // safe: true
    const string REVERSIBLE    = 'reversible';    // can be undone directly
    const string COMPENSATABLE = 'compensatable'; // can be undone via compensating action
    const string IRREVERSIBLE  = 'irreversible';  // final: true
}
```

Usage:

```php
<?php

#[Effect(entity: Subscription::class, op: Op::CREATE, reversible: Reversibility::COMPENSATABLE)]
#[Effect(entity: Charge::class, op: Op::CREATE, scope: Scope::EXTERNAL, system: 'stripe', reversible: Reversibility::IRREVERSIBLE)]
final class CreateSubscription implements Executable
{
    // ...
}
```

**Merge behaviour:** the compiler matches `#[Effect]` annotations to extracted steps by
correlating the `entity` type with the argument types visible in the step's raw AST data.
When a match is found, the attribute's properties override the inferred properties for that
step. When no match is found (the entity doesn't appear in the method body), the compiler
emits a warning and includes the attribute's data as an additional step at the end of the list.

**When to use:**
- The SubsystemRegistry has no binding for a dependency type
- An external system call is made through a generic HTTP client not bound to a subsystem
- Complex conditional paths produce `unresolved` steps that need accurate properties
- The call signature makes the verb ambiguous (e.g. a method named `process` that charges)

**When not to use:**
- Anything the SubsystemRegistry + VerbClassifier already resolves correctly
- Documenting which database columns change (too granular — use the subsystem capability)
- Domain labels (`system: 'billing'`) — that is namespace information, not effect information

---

## 6. Stable Node ID Scheme

### Primary identifier: kebab-case name

Node IDs are kebab-case semantic names derived from the class name:

```
App\Handler\Billing\CreateSubscription  →  create-subscription
App\Task\User\ValidatePaymentToken      →  validate-payment-token
App\Command\SendWeeklyReport            →  send-weekly-report
```

Algorithm: strip namespace, PascalCase → kebab-case.

Override with `#[NodeId('custom-name')]` when the derived name is ambiguous or collides.

### Semantic coordinate hash (cache invalidation)

The hash is computed from semantic coordinates, NOT from the AST structure:

```
SCP_HASH = sha256(
    primary_entity_class +
    operation_family    +    (create/read/update/delete/etc.)
    scope_level         +    (local/process/external)
    reversibility            (idempotent/reversible/compensatable/irreversible)
)
```

This hash is stable across: class renames, variable renames, file moves, comment additions,
log statement additions, internal refactors that preserve behavior. It changes only when the
actual semantic behavior changes.

Used for incremental compilation: when a node's source file changes, compare the new hash
to the cached hash. If equal, skip recompilation. If different, recompile only that node.

The hash is NOT the primary identifier. The kebab-case name serves that role. The hash is
internal to the incremental compilation cache.

---

## 7. Programmable API

### Compilation

```php
<?php

$graph = AceCompiler::create()
    ->subsystems(SubsystemRegistry::fromYaml('.phalanx/ace.yaml'))
    ->source(SourceRoot::at('src/'))
    ->outputDir('.phalanx/')
    ->compile();             // SemanticGraph
```

### Node Queries

```php
<?php

$node = $graph->node('create-subscription');   // Node|null

$node->id();                    // string — 'create-subscription'
$node->steps();                 // StepCollection
$node->reads();                 // string[] — subsystem names
$node->writes();                // string[]
$node->sends();                 // string[]
$node->commitmentBoundary();    // Step|null — first final step
$node->hasExternalCall();       // bool — any step with scope: external
$node->hasSagaPath();           // bool — any step with a compensate field
$node->warnings();              // string[] — unresolved steps or missing compensate
```

### Graph Traversal

```php
<?php

// Find nodes by surface characteristics
$graph->nodes()->readingFrom('user-store');        // NodeCollection
$graph->nodes()->writingTo('payment-gateway');     // NodeCollection
$graph->nodes()->withExternalCall();               // NodeCollection
$graph->nodes()->withFinalStep();                  // NodeCollection
$graph->nodes()->withUnresolvedSteps();            // NodeCollection — compiler warnings
$graph->nodes()->forDomain('billing');             // NodeCollection — namespace-based

// Blast radius
$graph->affectedBy('user-store');                  // reads + writes
$graph->downstreamOf('payment-gateway');           // nodes whose steps reference this subsystem
$graph->upstreamOf('create-subscription');         // nodes that reference this node via node:

// Step queries
$graph->node('create-subscription')
    ->steps()
    ->withVerb('call')
    ->withProperty('final');                       // StepCollection
```

### Loading a compiled graph

```php
<?php

// Load without recompiling (fast path for MCP server and runtime use)
$graph = SemanticGraph::fromYaml('.phalanx/semantic.graph.yaml');
$graph = SemanticGraph::fromJson('.phalanx/semantic.graph.json');
```

---

## 8. MCP Server

The MCP server mounts `.phalanx/semantic.graph.yaml` at startup and exposes four tools.
It runs as a lightweight async Phalanx daemon.

Recommended system prompt addition (or `CLAUDE.md` entry):

> Do not use `grep`, `find`, or read source files to discover logic. Query the RST MCP tools
> to orient yourself before reading any file.

### Tool: `rst_explore_domain`

Returns entry-point nodes for a given domain. Domain is matched against the class namespace.

Input:
```json
{ "domain": "billing" }
```

Output:
```json
[
  {
    "id": "create-subscription",
    "reads":  ["user-store"],
    "writes": ["user-store", "payment-gateway"],
    "sends":  ["event-bus"]
  },
  {
    "id": "cancel-subscription",
    "reads":  ["user-store"],
    "writes": ["user-store"]
  }
]
```

### Tool: `rst_inspect_node`

Returns the full node definition including all classified steps.

Input:
```json
{ "node_id": "create-subscription" }
```

Output: the full node block from `semantic.graph.yaml`, serialised to JSON.

### Tool: `rst_blast_radius`

Returns all nodes affected by changes to a given subsystem or node.

Input:
```json
{ "subsystem": "payment-gateway" }
```
or:
```json
{ "node_id": "create-subscription" }
```

Output:
```json
{
  "reads":  ["get-subscription-status"],
  "writes": ["create-subscription", "process-refund"],
  "sends":  [],
  "references": ["checkout-flow"]
}
```

### Tool: `rst_find`

Finds all nodes containing at least one step matching a verb and optional property filter.

Input:
```json
{ "verb": "call", "property": "final" }
```
or:
```json
{ "verb": "send" }
```

Output:
```json
[
  { "id": "create-subscription", "matching_step": 3 },
  { "id": "process-refund",      "matching_step": 1 }
]
```

Useful for pre-refactor audits: "show me everything with an irreversible external call before
I change the payment gateway interface."

---

## 9. Package Structure

```
packages/phalanx-rst/
├── OVERVIEW.md
├── SPEC.md
├── bin/
│   └── ace                             # CLI: compile, diff, validate
├── src/
│   ├── AceCompiler.php                 # fluent entry point
│   ├── Stage/
│   │   ├── DiscoverNodes.php           # PSR-4 classmap → class-string list
│   │   ├── ExtractSteps.php            # NodeVisitor → RawStepDraft[]
│   │   ├── ResolveSubsystems.php       # TypeTracker + registry → refs resolved
│   │   ├── ClassifySteps.php           # VerbClassifier + property inheritance
│   │   ├── LinkNodes.php               # cross-refs + commitment boundary detection
│   │   └── EmitGraph.php              # → SemanticGraph value object + YAML/JSON files
│   ├── Visitor/
│   │   ├── InvokeBodyVisitor.php       # nikic NodeVisitor for __invoke body
│   │   └── TypeTracker.php            # constructor reflection → property type map
│   ├── Registry/
│   │   ├── SubsystemRegistry.php
│   │   └── VerbClassifier.php
│   ├── Attribute/
│   │   ├── Effect.php                  # #[Effect] escape hatch
│   │   ├── Subsystem.php              # #[Subsystem('name')] on interfaces
│   │   └── NodeId.php                 # #[NodeId('custom-id')] override
│   ├── Graph/
│   │   ├── SemanticGraph.php
│   │   ├── Node.php
│   │   ├── Step.php
│   │   ├── NodeCollection.php
│   │   └── StepCollection.php
│   └── Mcp/
│       ├── RstMcpServer.php
│       └── Tool/
│           ├── ExploreDomain.php
│           ├── InspectNode.php
│           ├── BlastRadius.php
│           └── Find.php
├── tests/
│   ├── Unit/
│   │   ├── Registry/
│   │   ├── Visitor/
│   │   └── Graph/
│   └── Fixture/
│       ├── php/                        # source PHP files with known content
│       └── expected/                   # expected semantic.graph.yaml outputs
├── composer.json
└── phpunit.xml.dist
```

---

## 10. Dependencies

```json
{
    "require": {
        "php": "^8.4",
        "phalanx/core": "^0.6",
        "nikic/php-parser": "^5.0",
        "symfony/yaml": "^7.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0",
        "phpstan/phpstan": "^1.10"
    },
    "suggest": {
        "phalanx/ai": "Required for MCP server tool integration"
    }
}
```

---

## 11. Known Limitations (v1 Scope)

### In scope

- Phalanx `Scopeable` and `Executable` classes with constructor-injected dependencies
- `__invoke()` method body extraction via nikic/php-parser
- Subsystem resolution: manifest + `#[Subsystem]` attribute + naming convention
- Six-stage compiler pipeline producing `semantic.graph.yaml` and `semantic.graph.json`
- Four MCP tools: `explore_domain`, `inspect_node`, `blast_radius`, `find`
- `#[Effect]` attribute for manual step annotation

### Out of scope (v1)

- **Local variable type tracking** beyond `$this->property`: multi-step assignment chains
  require PHPStan data flow analysis. Steps using locally-scoped variables emit as
  `unresolved` with a warning.
- **Trait and abstract class flattening**: method bodies inherited via traits or abstract
  base classes are not merged. Only the concrete class's `__invoke()` is extracted.
- **Non-PSR-4 source files**: the compiler assumes PSR-4 autoloading. Files outside the
  autoload classmap are not discovered.
- **Incremental compilation**: v1 performs a full rebuild on every invocation. The semantic
  coordinate hash is computed and stored for future incremental support.
- **Cross-language SCP emitters**: the schema is language-agnostic; emitters for other
  languages are not in scope for this package.
- **Dynamic subsystem bindings**: only constructor-injected types are resolved. Runtime
  service resolution through non-typed parameters is not tracked.

### Accuracy expectation

Approximately 80% of Phalanx handlers compile to complete, warning-free nodes when the
`ace.yaml` manifest binds all major service types. The remaining ~20% produce `unresolved`
steps (warnings, not failures) and require `#[Effect]` annotations for full accuracy.

The actual ratio depends on codebase adherence to Phalanx conventions. Codebases with deep
`__invoke()` bodies, trait usage, or generic HTTP clients will produce more unresolved steps.
