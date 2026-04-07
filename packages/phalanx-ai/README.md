<p align="center">
  <img src="brand/logo.svg" alt="Phalanx" width="520">
</p>

# phalanx/ai

> **Phalanx** is a first-principles rethinking of what PHP can be when modern language features and a decade of async community work are treated as the foundation, not an afterthought. [Read more](https://github.com/havy-tech/phalanx-core#phalanx-core---async-php) in the core library.

An agentic runtime for PHP 8.4+ that treats LLM interactions as scoped, typed, stream-native computations. Define tools as invokable classes, wire providers as services, and let the Phalanx runtime handle concurrency, retries, streaming, and cleanup.

Phalanx/ai brings concurrent tool execution, streaming with backpressure, and multi-agent coordination to PHP -- building on the same scope-driven execution model and proven async foundations that power the rest of the framework.

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Agents](#agents)
  - [Agent Definition](#agent-definition)
  - [Agent Turns](#agent-turns)
  - [Multi-Step Reasoning](#multi-step-reasoning)
- [Tools](#tools)
  - [Tool Classes](#tool-classes)
  - [Tool Results and Dispositions](#tool-results-and-dispositions)
  - [Tool Bundles](#tool-bundles)
- [Providers](#providers)
  - [Provider Configuration](#provider-configuration)
  - [Multi-Provider Strategies](#multi-provider-strategies)
- [Streaming](#streaming)
  - [Event Channel](#event-channel)
  - [SSE Delivery](#sse-delivery)
  - [WebSocket Delivery](#websocket-delivery)
  - [Token Accumulator](#token-accumulator)
- [Structured Output](#structured-output)
- [Pipelines](#pipelines)
- [Conversation Memory](#conversation-memory)
- [CLI Agents](#cli-agents)
- [Observability](#observability)
- [Examples](#examples)

## Installation

```bash
composer require phalanx/ai
```

Requires PHP 8.4+, `phalanx/core`, `phalanx/stream`, `phalanx/http`.

Optional: `phalanx/redis` for conversation memory and pub/sub coordination, `phalanx/postgres` for persistent storage, `phalanx/ws-server` for real-time delivery, `phalanx/console` for CLI agents.

## Quick Start

```php
<?php

use Phalanx\Ai\Agent;
use Phalanx\Ai\AiServiceBundle;
use Phalanx\Ai\Message\Message;
use Phalanx\Application;

[$app, $scope] = Application::starting(['ANTHROPIC_API_KEY' => $context['ANTHROPIC_API_KEY']])
    ->providers(new AiServiceBundle())
    ->compile()
    ->boot();

$events = $scope->execute(
    Agent::quick('You are a helpful assistant.')
        ->message('What is the capital of France?')
);

$result = AgentResult::awaitFrom($events, $scope);
echo $result->text; // "Paris is the capital of France."

$scope->dispose();
$app->shutdown();
```

That's a single LLM call. The architecture scales to multi-step agents with concurrent tools, streaming responses, and cross-process coordination -- without changing the programming model.

## Agents

### Agent Definition

An agent is an invokable class that declares its system prompt, tools, and provider preference. PHP 8.4 property hooks keep it declarative:

```php
<?php

use Phalanx\Ai\AgentDefinition;
use Phalanx\Ai\AgentLoop;
use Phalanx\Ai\Turn;
use Phalanx\ExecutionScope;
use Phalanx\Task\HasTimeout;
use Phalanx\Task\Retryable;
use Phalanx\Concurrency\RetryPolicy;

final class ProductAssistant implements AgentDefinition, Retryable, HasTimeout
{
    public string $instructions {
        get => <<<'PROMPT'
            You are a product specialist. Use the available tools to search
            inventory, check pricing, and answer customer questions accurately.
            Always cite specific product IDs in your responses.
            PROMPT;
    }

    public RetryPolicy $retryPolicy {
        get => RetryPolicy::exponential(3);
    }

    public float $timeout {
        get => 30.0;
    }

    public function tools(): array
    {
        return [
            SearchProducts::class,
            GetProductById::class,
            CheckInventory::class,
        ];
    }

    public function provider(): ?string
    {
        return 'anthropic';
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        return AgentLoop::run(Turn::begin($this), $scope);
    }
}
```

The behavioral interfaces -- `Retryable`, `HasTimeout` -- are the same ones used by every Phalanx task. Agents aren't special; they're computations with identity.

### Agent Turns

Each interaction is one **turn** -- one scope. `Turn` is an immutable builder that carries configuration through the fluent API:

```php
<?php

$result = AgentResult::awaitFrom(
    $scope->execute(
        Agent::from(new ProductAssistant())
            ->message('Do you have wireless keyboards under $50?')
            ->maxSteps(5)
    ),
    $scope,
);

echo $result->text;
echo $result->usage->total; // token count
echo $result->steps;        // steps taken
```

Multi-turn conversations pass the conversation forward:

```php
<?php

$turn1Result = AgentResult::awaitFrom(
    $scope->execute(
        Agent::from(new ProductAssistant())
            ->message('Find wireless keyboards')
    ),
    $scope,
);

$turn2Result = AgentResult::awaitFrom(
    $scope->execute(
        Agent::from(new ProductAssistant())
            ->conversation($turn1Result->conversation)
            ->message('Which one has the best reviews?')
    ),
    $scope,
);
```

### Multi-Step Reasoning

When an agent calls tools iteratively (think -> act -> observe -> think), `maxSteps` controls the loop count. The `onStep` hook lets you observe or intercept each round:

```php
<?php

use Phalanx\Ai\StepAction;
use Phalanx\Ai\StepResult;
use Phalanx\Ai\Message\Message;

Agent::from(new ResearchAssistant())
    ->maxSteps(5)
    ->onStep(static function (StepResult $step, ExecutionScope $scope): StepAction {
        if ($step->toolCalls->count() > 10) {
            return StepAction::finalize('Too many tool calls, summarizing...');
        }

        if ($step->number === 4) {
            return StepAction::inject(
                Message::system('You have one step remaining. Provide your final answer.')
            );
        }

        return StepAction::continue();
    });
```

`StepAction::finalize()` stops the loop and returns the given text. `StepAction::inject()` appends a message to the conversation before the next LLM call. `StepAction::continue()` lets the loop proceed normally.

## Tools

### Tool Classes

Tools are invokable classes. The constructor defines the input schema. `__invoke` does the work. The class name is the identity:

```php
<?php

use Phalanx\Ai\Tool\Tool;
use Phalanx\Ai\Tool\ToolOutcome;
use Phalanx\Ai\Tool\Param;
use Phalanx\Scope;

final class SearchDatabase implements Tool
{
    public string $description {
        get => 'Search the product database by query string';
    }

    public function __construct(
        #[Param('The search query')]
        private readonly string $query,
        #[Param('Maximum results to return')]
        private readonly int $limit = 10,
    ) {}

    public function __invoke(Scope $scope): ToolOutcome
    {
        $products = $scope->service(PgPool::class)->execute(
            'SELECT * FROM products WHERE name ILIKE $1 LIMIT $2',
            ["%{$this->query}%", $this->limit]
        );

        return ToolOutcome::data($products);
    }
}
```

`Tool` extends `SelfDescribed` from `phalanx/core`. The `$description` property hook is part of that interface, not specific to tools -- HTTP routes and CLI commands implement the same contract. This means tools can be introspected, listed, and documented through the same framework-wide interface as every other named computation.

The JSON schema sent to the LLM is generated from the constructor signature and `#[Param]` attributes. No separate schema definition. No mapping layer. The tool *is* the schema.

Constructor promotion means PHP's type system validates inputs before `__invoke` runs. An LLM that sends `{"limit": "banana"}` fails at hydration, not at runtime.

### Tool Results and Dispositions

`ToolOutcome` carries data back to the LLM and signals what should happen next:

```php
<?php

// Normal result -- continue the agent loop
return ToolOutcome::data($searchResults);

// Terminate the agent loop -- this becomes the final output
return ToolOutcome::done($finalMessage, reason: 'Transferred to human');

// Delegate to another agent -- the sub-agent's result becomes the tool result
return ToolOutcome::handoff(Turn::begin(new BillingSpecialist())->message($question));

// Escalate to a human via pub/sub
return ToolOutcome::escalate('Customer requesting refund above $500 threshold');

// Retry with a hint appended to context
return ToolOutcome::retry('The query returned no results. Try broader search terms.');
```

Tools express *intent*, not just *data*. The agent loop interprets dispositions -- `Continue`, `Terminate`, `Delegate`, `Escalate`, `Retry` -- so tools drive control flow without the LLM needing to orchestrate it.

### Tool Bundles

Group related tools:

```php
<?php

use Phalanx\Ai\Tool\ToolBundle;

final class DatabaseTools implements ToolBundle
{
    public function tools(): array
    {
        return [
            SearchDatabase::class,
            GetProductById::class,
            ListCategories::class,
        ];
    }
}

// Agents accept individual tools, bundles, or both
public function tools(): array
{
    return [new DatabaseTools(), CustomTool::class];
}
```

## Providers

### Provider Configuration

Providers are Phalanx services. Register them through `AiServiceBundle` with environment variables, or build `ProviderConfig` directly:

```php
<?php

use Phalanx\Ai\Provider\ProviderConfig;

$config = ProviderConfig::create()
    ->anthropic(apiKey: $key, model: 'claude-sonnet-4-20250514')
    ->openai(apiKey: $key2, model: 'gpt-4o')
    ->ollama(model: 'llama3', baseUrl: 'http://localhost:11434');
```

Each provider implements `LlmProvider` -- a single method that returns a reactive stream:

```php
<?php

interface LlmProvider
{
    public function generate(GenerateRequest $request): Emitter;
}
```

Every provider returns an `Emitter` of `AgentEvent`, not a completed response. Streaming is the default. Non-streaming is "collect all events, return the final one."

### Multi-Provider Strategies

Provider selection maps directly to Phalanx concurrency primitives:

```php
<?php

use Phalanx\Ai\Provider\ProviderStrategy;

// Always uses the first configured provider
$provider = ProviderStrategy::primary($anthropic, $openai);

// Try in order, use first success (maps to $scope->any())
$provider = ProviderStrategy::fallback($anthropic, $openai);

// Distribute across providers
$provider = ProviderStrategy::roundRobin($anthropic, $openai, $ollama);
```

## Streaming

### Event Channel

Every agent execution emits events. The event channel is the backbone -- there's one code path, not separate streaming and non-streaming modes:

```php
<?php

$events = AgentLoop::run($turn, $scope);

// Non-streaming: collect final result
$result = AgentResult::awaitFrom($events, $scope);

// Streaming: pipe to SSE
return SseResponse::from($events, $scope);
```

`$events` is an `Emitter` from `phalanx/stream`. It supports `filter`, `map`, `onEach`, `throttle`, `bufferWindow`, `merge`, and every other operator. The push/pull backpressure model means a slow SSE client pauses the LLM stream -- no unbounded buffering.

Events emitted during execution:

| Event | When |
|-------|------|
| `LlmStart` | Before each LLM API call |
| `TokenDelta` | Each streamed token from the LLM |
| `TokenComplete` | LLM response finished |
| `ToolCallStart` | Tool execution begins |
| `ToolCallComplete` | Tool execution finished |
| `StepComplete` | One think-act-observe cycle done |
| `StructuredOutput` | Validated structured output available |
| `AgentComplete` | Agent finished (carries `AgentResult`) |
| `AgentError` | Agent failed |
| `Escalation` | Tool requested human escalation |

### SSE Delivery

Pipe a token stream directly into an SSE response:

```php
<?php

use Phalanx\ExecutionScope;
use Phalanx\Http\RequestScope;
use Phalanx\Http\Sse\SseResponse;
use Phalanx\Task\Executable;

final readonly class ChatSseHandler implements Executable
{
    public function __invoke(ExecutionScope $scope): mixed
    {
        /** @var RequestScope $scope */
        $body = $scope->body->json();

        $turn = Turn::begin(new ChatAssistant())
            ->conversation(Conversation::fromArray($body['messages']))
            ->message(Message::user($body['input']))
            ->stream();

        $events = AgentLoop::run($turn, $scope);

        return SseResponse::from(
            $events->filter(static fn($e) => $e->kind->isUserFacing()),
            $scope,
            event: 'chat',
        );
    }
}
```

```php
<?php

use Phalanx\Http\RouteGroup;

$routes = RouteGroup::of([
    'POST /chat' => ChatSseHandler::class,
]);
```

Client disconnect propagates through the event channel -> the LLM request cancels -> the scope disposes. No orphaned connections.

### WebSocket Delivery

Same pattern, different transport:

```php
<?php

use Phalanx\Scope;
use Phalanx\Task\Scopeable;
use Phalanx\WebSocket\WsMessage;
use Phalanx\WebSocket\WsScope;

final readonly class AgentWsHandler implements Scopeable
{
    public function __invoke(Scope $scope): mixed
    {
        assert($scope instanceof WsScope);
        $conn = $scope->connection;
        $conversation = Conversation::create()->system('You are a helpful assistant.');

        foreach ($conn->inbound->consume() as $msg) {
            if (!$msg->isText) {
                continue;
            }

            $turn = Turn::begin(new ChatAssistant())
                ->conversation($conversation)
                ->message(Message::user($msg->decode()['text']))
                ->stream();

            $events = AgentLoop::run($turn, $scope);

            foreach ($events($scope) as $event) {
                if ($event->kind === AgentEventKind::TokenDelta) {
                    $conn->send(WsMessage::json([
                        'type' => 'token', 'text' => $event->data->text,
                    ]));
                }
            }
        }

        return null;
    }
}
```

```php
<?php

use Phalanx\WebSocket\WsRouteGroup;

$ws = WsRouteGroup::of([
    '/ws/agent' => AgentWsHandler::class,
]);
```

### Token Accumulator

For the common case of "stream tokens to the client AND need the final result":

```php
<?php

use Phalanx\Ai\Stream\TokenAccumulator;

$accumulator = TokenAccumulator::from($events, $scope);

// Stream text fragments as they arrive
$tokens = $accumulator->text();

// After the stream completes, get the full result
$result = $accumulator->result();
$conversation = $accumulator->conversation();
```

## Structured Output

Define output shapes as PHP classes. The SDK generates JSON schema from the class structure and validates the LLM response:

```php
<?php

use Phalanx\Ai\Schema\Structured;
use Phalanx\Ai\Tool\Param;

#[Structured(description: 'Sentiment analysis result')]
final readonly class SentimentResult
{
    public function __construct(
        #[Param('The detected sentiment')]
        public SentimentKind $sentiment,
        #[Param('Confidence score between 0 and 1')]
        public float $confidence,
        #[Param('Brief explanation')]
        public string $reasoning,
    ) {}
}

enum SentimentKind: string
{
    case Positive = 'positive';
    case Negative = 'negative';
    case Neutral = 'neutral';
    case Mixed = 'mixed';
}

$result = AgentResult::awaitFrom(
    $scope->execute(
        Agent::quick('Analyze sentiment.')
            ->message('I love this product!')
            ->output(SentimentResult::class)
    ),
    $scope,
);

$result->structured->sentiment;  // SentimentKind::Positive
$result->structured->confidence; // 0.95
```

PHP enums become JSON Schema `enum` constraints. Typed properties become required fields. Validation failures re-prompt the LLM with the error -- up to the agent's `RetryPolicy`.

## Pipelines

For workflows more complex than a single agent turn -- chained transformations, conditional routing, concurrent branches:

```php
<?php

use Phalanx\Ai\Pipeline\Pipeline;

$pipeline = Pipeline::create()
    ->step(new ClassifyIntent())
    ->branch(fn(IntentClassification $intent) => match ($intent->category) {
        'billing'   => new BillingAgent(),
        'technical' => new TechnicalAgent(),
        'sales'     => new SalesAgent(),
        default     => new GeneralAgent(),
    })
    ->step(new FormatResponse());

$result = $scope->execute($pipeline->run($inputData));
```

`fan()` runs multiple agents concurrently and merges results:

```php
<?php

$pipeline = Pipeline::create()
    ->step(new ParseDocument())
    ->fan([
        new ExtractEntities(),
        new AnalyzeSentiment(),
        new SummarizeContent(),
    ])
    ->step(new MergeAnalysis());
```

`fan()` maps to `$scope->concurrent()`. The merge step receives an array of all branch results.

## Conversation Memory

Persist conversation history across turns with Redis or Postgres:

```php
<?php

use Phalanx\Ai\Memory\ConversationMemory;

$memory = $scope->service(ConversationMemory::class);

$conversation = $memory->load($sessionId);
$conversation = $conversation->user($newMessage);

$result = AgentResult::awaitFrom(
    $scope->execute(
        Agent::from(new ChatAssistant())->conversation($conversation)
    ),
    $scope,
);

$memory->save($sessionId, $result->conversation);
```

Register with Redis:

```php
<?php

$services->singleton(ConversationMemory::class)
    ->factory(fn($redis) => new RedisConversationMemory($redis, ttl: 3600));
```

Or Postgres:

```php
<?php

$services->singleton(ConversationMemory::class)
    ->factory(fn($pg) => new PgConversationMemory($pg, table: 'conversations'));
```

## CLI Agents

Build an interactive CLI agent in one line:

```php
<?php

use Phalanx\Ai\Cli\AgentRepl;

$command = AgentRepl::command(new ProductAssistant());
```

```
$ php app.php agent:chat --verbose
Session: cli_67e3a1b2
Agent: ProductAssistant

> Find wireless keyboards under $50
[tool] search_products({"query":"wireless keyboard","limit":10})
[done] search_products +24.3ms

I found 3 wireless keyboards under $50:
1. LogiKey Pro (KB-2847) — $34.99, 47 in stock
2. TypeMaster Wireless (KB-1923) — $42.99, 12 in stock
3. EcoBoard Mini (KB-3012) — $29.99, 83 in stock
```

The `--session` flag resumes a previous conversation. The `--verbose` flag shows tool calls with timing. The same agent, same tools, same streaming -- different transport.

## Observability

Phalanx's built-in tracing extends to AI operations. Every LLM call, tool execution, and pipeline step appears in the trace:

```
PHALANX_TRACE=1 php server.php
```

```
    0ms  STRT  ProductAssistant
    1ms  LLM>    anthropic/claude-sonnet-4-20250514  (127 tokens in)
  412ms  LLM<    anthropic  +411ms  (83 tokens out, 2 tool_calls)
  413ms  TOOL>   concurrent(2)
  414ms  EXEC    SearchProducts{query:"wireless keyboard", limit:10}
  416ms  EXEC    CheckInventory{sku:"KB-2847"}
  438ms  DONE    SearchProducts  +24ms
  441ms  DONE    CheckInventory  +25ms
  442ms  TOOL<   concurrent(2) joined  +29ms
  443ms  LLM>    anthropic/claude-sonnet-4-20250514  (312 tokens in)
  891ms  LLM<    anthropic  +448ms  (156 tokens out, 0 tool_calls)
  892ms  DONE  ProductAssistant  +892ms  (2 steps, 4 tool_calls, 678 tokens)
```

Tool class names and constructor args appear in the trace because they're typed invokables -- not anonymous closures. `SearchProducts{query:"wireless keyboard"}` tells you exactly what happened without opening a debugger.

## Examples

Three complete examples demonstrate progressive architectural complexity:

| Example | Transport | Architecture |
|---------|-----------|-------------|
| [Support Triage](examples/support-triage/) | SSE | Single agent, 4 tools, structured output |
| [Research Agent](examples/research-agent/) | WebSocket | Multi-agent with sub-agent delegation |
| [Multi-Tenant Fleet](examples/multi-tenant-fleet/) | Redis pub/sub | Gateway + workers, cross-process coordination |

## Package Dependencies

```
phalanx/ai
├── phalanx/core     (scope, tasks, concurrency, DI, lifecycle)
├── phalanx/stream   (Emitter, Channel, backpressure, operators)
├── phalanx/http     (async HTTP client for LLM API calls)
└── optional:
    ├── phalanx/redis     (conversation memory, pub/sub coordination)
    ├── phalanx/postgres  (persistent memory, LISTEN/NOTIFY tasks)
    ├── phalanx/ws-server (real-time agent sessions)
    └── phalanx/console   (CLI agent REPL)
```
