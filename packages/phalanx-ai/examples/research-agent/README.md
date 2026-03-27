# Multi-Document Research Agent

An agent that processes uploaded documents in parallel, builds shared context through sub-agents, and streams analysis with live progress over WebSocket.

## Quick Start

```bash
cd packages/phalanx-ai/examples/research-agent
composer install
ANTHROPIC_API_KEY=sk-... php server.php
```

Then connect via WebSocket to `ws://localhost:8080/research` and send:

```json
{"type":"research","documents":[{"name":"Q1-report.pdf","type":"pdf","path":"/uploads/q1.pdf"}],"question":"Summarize revenue trends"}
```

## What This Solves

A user uploads 3-5 documents and asks a question requiring cross-referencing: "Compare the revenue trends in Q1-report.pdf and Q2-report.pdf, then check if the projections in forecast.csv align with what happened."

This is the use case that drives PHP developers to Python. Current approaches: stuff everything into one massive prompt (hits token limits), use a Python RAG sidecar (two languages, complex deployment), or queue-based processing (minutes with no feedback).

The core challenge is coordinating multiple concurrent operations with partial results visible as they happen.

## Architecture

```
Browser (WebSocket client)
    |
    | {"type":"research","documents":[...],"question":"..."}
    |
    v
Phalanx WebSocket Server
    |
    +-- ResearchAgent (main agent, 60s timeout)
            |
            +-- ExtractDocumentContent("Q1-report.pdf") ─┐
            |       └── SummarizationAgent (child scope)  │
            |                                              ├── concurrent
            +-- ExtractDocumentContent("Q2-report.pdf") ─┤
            |       └── SummarizationAgent (child scope)  │
            |                                              │
            +-- QuerySpreadsheet("forecast.csv")         ─┘
            |       └── DataAnalyst (child scope)
            |
            +-- CrossReference (cross-ref summaries)
            |
            +-- Stream final analysis
```

Each document extraction spawns a focused sub-agent. The main `ResearchAgent` never sees raw document text -- it receives structured summaries. This keeps the main context window lean.

## Files

| File | Purpose |
|------|---------|
| `src/ResearchAgent.php` | Main agent with retry policy and 60s timeout |
| `src/SummarizationAgent.php` | Sub-agent for document summarization |
| `src/DataAnalyst.php` | Sub-agent for spreadsheet analysis |
| `src/Tools/ExtractDocumentContent.php` | Spawns `SummarizationAgent` in a child scope |
| `src/Tools/QuerySpreadsheet.php` | Spawns `DataAnalyst` in a child scope |
| `src/Tools/CrossReference.php` | Cross-references data across document summaries |
| `server.php` | WebSocket handler showing the complete flow |

## Key Patterns

**Sub-agent delegation.** `ExtractDocumentContent` and `QuerySpreadsheet` each spawn sub-agents via `$scope->execute()` inside a child scope. Each sub-agent has its own timeout, tool set, and token budget -- but shares the parent's cancellation token. User disconnect tears everything down.

**Concurrent tool execution with sub-agents.** When the LLM requests extraction of 3 documents simultaneously, all 3 tool invocations run concurrently. Each spawns its own sub-agent. 5 documents process in the time of the slowest one, not the sum.

**WebSocket progress streaming.** The event channel emits `ToolCallStart` and `ToolCallComplete` for each document extraction, giving the frontend enough data to render a multi-stage progress visualization: document icons animate as they're processed, cross-referencing shows activity, then the analysis streams in token by token.

## What the User Sees

```json
{"type":"progress","stage":"tool","label":"Reading: Q1-report.pdf","tool":"extract_document_content"}
{"type":"progress","stage":"tool","label":"Reading: Q2-report.pdf","tool":"extract_document_content"}
{"type":"progress","stage":"tool","label":"Analyzing: forecast.csv","tool":"query_spreadsheet"}
{"type":"progress","stage":"tool_done","tool":"extract_document_content","ms":2341.2}
{"type":"progress","stage":"tool_done","tool":"extract_document_content","ms":2587.8}
{"type":"progress","stage":"tool_done","tool":"query_spreadsheet","ms":3102.4}
{"type":"progress","stage":"step","step":1,"tokens":4521}
{"type":"progress","stage":"tool","label":"Cross-referencing documents...","tool":"cross_reference"}
{"type":"progress","stage":"tool_done","tool":"cross_reference","ms":18.3}
{"type":"token","text":"## Revenue Trend Analysis\n\n### Q1 vs Q2 Comparison"}
...
{"type":"complete","tokens":8247,"steps":3,"elapsed":12}
```

All three document extractions completed in ~3.1 seconds (concurrent), not 8+ seconds (sequential).

## Usage

```php
<?php

use Acme\ResearchAgent;
use Phalanx\Ai\Turn;
use Phalanx\Ai\Message\Message;

$turn = Turn::begin(new ResearchAgent())
    ->message(Message::user(
        "Documents:\n" .
        "- Q1-report.pdf (pdf): /uploads/q1.pdf\n" .
        "- Q2-report.pdf (pdf): /uploads/q2.pdf\n" .
        "- forecast.csv (csv): /uploads/forecast.csv\n\n" .
        "Research question: Compare revenue trends and check if projections aligned with actuals."
    ))
    ->maxSteps(8);
```
