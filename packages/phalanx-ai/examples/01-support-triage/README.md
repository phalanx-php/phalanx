# Support Ticket Triage

A single agent classifies support tickets, gathers customer context, and drafts a response -- all streaming to the support agent's browser in real time via SSE.

## What This Solves

Every SaaS with a support system faces the same workflow: ticket arrives, human reads it, classifies priority, writes a response (often copying templates), routes to the right team. 3-8 minutes per ticket. Companies hire more support staff or let response times degrade.

PHP-specific pain: Laravel apps can call OpenAI to classify a ticket, but classification and response drafting become two blocking API calls (6-10 seconds). There's no way to enrich classification with customer context without making it sequential. Streaming the draft to the browser requires hacking `ob_flush()`. Tool calling is completely manual.

This example solves the concurrent tool execution + streaming + structured output trifecta in a single request.

## Architecture

```
Browser (SSE client)
    |
    | POST /triage {ticket_id, customer_email, subject, body}
    |
    v
Phalanx HTTP Server
    |
    +-- SupportTriageAgent
    |       |
    |       +-- LookupCustomer      ─┐
    |       +-- SearchKnowledgeBase   ├── concurrent (time of slowest, not sum)
    |       +-- GetRecentTickets      │
    |       +-- CheckServiceStatus   ─┘
    |       |
    |       +-- TriageResult (structured output)
    |
    +-- SSE stream: tool activity + tokens + structured result
    |
    +-- onDispose: persist triage to database
```

## Files

| File | Purpose |
|------|---------|
| `SupportTriageAgent.php` | Agent definition with instructions, tools, timeout |
| `TriageResult.php` | `#[Structured]` output class with priority, category, draft |
| `TicketPriority.php` | Enum: critical, high, medium, low |
| `TicketCategory.php` | Enum: billing, technical, account, feature-request, bug-report |
| `Tools/LookupCustomer.php` | Queries customer account and recent activity |
| `Tools/SearchKnowledgeBase.php` | Full-text search against KB articles |
| `Tools/GetRecentTickets.php` | Retrieves customer's recent support history |
| `Tools/CheckServiceStatus.php` | Checks for active incidents and degraded services |
| `server.php` | SSE endpoint showing the complete request flow |

## Key Patterns

**Concurrent tool execution.** When the LLM requests multiple tools in one response, all execute in parallel via `$scope->concurrent()`. `LookupCustomer` + `SearchKnowledgeBase` + `CheckServiceStatus` run simultaneously -- total time equals the slowest, not the sum.

**Structured output with streaming.** `TriageResult` carries priority, category, summary, and draft response as typed PHP properties. The LLM response validates against the generated JSON schema. The browser receives *both* the streaming draft text *and* the final structured classification.

**Automatic persistence on dispose.** `$scope->onDispose()` fires after the SSE stream completes (or client disconnects). The triage result persists to the database regardless of how the stream ended.

## What the Browser Sees

```
event: triage
data: {"type":"tool_start","tool":"lookup_customer"}

event: triage
data: {"type":"tool_done","tool":"lookup_customer","ms":23.4}

event: triage
data: {"type":"tool_start","tool":"search_knowledge_base"}

event: triage
data: {"type":"tool_done","tool":"search_knowledge_base","ms":41.2}

event: triage
data: {"type":"triage","priority":"medium","category":"billing","auto_resolvable":false}

event: triage
data: {"type":"token","text":"Hi Sarah,\n\nThank you for reaching out about"}
...
```

The frontend renders tool activity as status indicators and streams the draft word by word. The support agent watches the AI "thinking" -- then edits the draft and sends.

## Usage

```php
<?php

use Phalanx\Ai\Agent;
use Phalanx\Ai\Message\Message;
use Phalanx\Ai\Turn;

$turn = Turn::begin(new SupportTriageAgent())
    ->message(Message::user(
        "Ticket from: sarah@example.com\n" .
        "Subject: CSV export failing since yesterday\n\n" .
        "Every time I try to export my monthly report, it spins for 30 seconds then shows an error..."
    ))
    ->output(TriageResult::class)
    ->maxSteps(4);
```
