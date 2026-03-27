# Multi-Tenant Customer Support Fleet

A production architecture for hosting AI support agents across multiple tenants with real-time streaming, human escalation, and horizontal scaling -- all in PHP.

## Quick Start

Start the gateway:

```bash
cd packages/phalanx-ai/examples/multi-tenant-fleet
composer install
php gateway.php
```

Start one or more workers (in separate terminals):

```bash
cd packages/phalanx-ai/examples/multi-tenant-fleet
php worker.php
```

Workers scale horizontally. Add more processes to handle more concurrent conversations.

## What This Solves

A SaaS platform hosts support agents for multiple tenants. Each tenant has their own knowledge base, brand guidelines, escalation rules, and connected data sources. Concurrent users across tenants number in the hundreds. Each conversation is a long-lived WebSocket session with streaming AI responses. Some conversations need to escalate to human agents in real time.

In existing PHP, this is impossible without a Node.js/Python service for the AI + WebSocket layer, or a monolithic Swoole app that attempts everything in one process.

This example does it with multiple Phalanx processes coordinated via Redis pub/sub.

## Architecture

```
                                ┌─────────────────────┐
                                │   Redis Pub/Sub     │
                                │   (event bus)       │
                                └───┬──────┬──────┬───┘
                                    │      │      │
          ┌─────────────────────────┼──────┼──────┼──────────────────────┐
          │                         │      │      │                      │
┌─────────▼──────────┐   ┌─────────▼──┐  ┌▼──────▼────────┐  ┌──────────▼─────────┐
│  Gateway Process   │   │  Worker 1  │  │   Worker 2     │  │  Worker N          │
│  (HTTP + WS)       │   │  (agents)  │  │   (agents)     │  │  (agents)          │
│                    │   │            │  │                │  │                    │
│  - WebSocket mgmt  │   │  - Agent   │  │  - Agent       │  │  - Agent           │
│  - Session routing │   │    turns   │  │    turns       │  │    turns           │
│  - SSE fallback    │   │  - Tool    │  │  - Tool        │  │  - Tool            │
│  - Human agent UI  │   │    exec    │  │    exec        │  │    exec            │
└────────────────────┘   └────────────┘  └────────────────┘  └────────────────────┘
          │
          │  Postgres (conversations, tenant configs, KB, escalation log)
          │
```

**Gateway**: Handles WebSocket connections for customers and human agents. Routes messages to workers via Redis. Does not execute agents.

**Workers**: Subscribe to `agent:tasks` Redis channel. Execute agent turns in isolated child scopes. Stream tokens back through Redis pub/sub.

**Redis**: The event bus connecting everything. Sub-millisecond message routing. No RabbitMQ. No Kafka. No SQS.

## Files

| File | Purpose |
|------|---------|
| `src/TenantAgentFactory.php` | Builds tenant-specific agents from database config |
| `src/TenantSupportAgent.php` | Runtime-configurable agent per tenant |
| `src/Tools/TransferToHuman.php` | Terminates agent loop, publishes escalation to dashboard |
| `src/Tools/TenantKbSearch.php` | Tenant-scoped knowledge base search |
| `gateway.php` | Gateway process: customer WS + dashboard WS + HTTP |
| `worker.php` | Worker process: agent execution + Redis pub/sub |

## Key Patterns

**Cross-process streaming via pub/sub.** Workers generate tokens and publish them to Redis. The gateway receives them and forwards to WebSocket connections. Customers see real-time streaming even though the AI runs in a separate process. No shared memory. No HTTP calls between processes.

**Tool-driven escalation.** The AI doesn't "decide" to escalate by generating text. `TransferToHuman` returns `ToolOutcome::done()` which terminates the agent loop cleanly, persists the escalation to Postgres, and notifies the dashboard -- all atomically within the tool's execution.

**Tenant-specific agents at runtime.** Each tenant gets a custom agent with custom tools, system prompt, provider, and escalation rules. `TenantAgentFactory` builds this from the database (cached in Redis). Adding a new tenant is a database insert, not a code deployment.

**Scoped child processes for isolation.** Each agent task runs in a `child()` scope. If a tenant's agent crashes, only that child scope tears down. Other tenants' agents are unaffected. The worker continues listening.

**Single-port gateway.** HTTP health checks, customer WebSocket, and agent dashboard WebSocket all run on the same port. Phalanx's runner handles protocol detection. One load balancer rule. One DNS record. One SSL certificate.

## The Complete Flow

1. **Customer connects** via WebSocket: `ws://gateway:8080/ws/chat/tenant_123/session_abc`

2. **Customer sends a message.** Gateway publishes to `agent:tasks` via Redis.

3. **A worker picks up the task.** Resolves tenant config (cached in Redis, backed by Postgres), loads conversation history, executes the agent turn.

4. **Tokens stream back** via Redis pub/sub: worker -> `session:abc:response` -> gateway -> customer's WebSocket. Real-time typing.

5. **If a tool runs** (KB search, CRM lookup), the worker publishes a "thinking" event. Customer sees "Searching knowledge base..." in the chat.

6. **If the AI escalates**, `TransferToHuman` publishes to `tenant:123:escalations`. Gateway forwards to human agent dashboard.

7. **A human agent claims the session.** Their "claim" message publishes to `session:abc:response`, customer sees "Hi, I'm Sarah. I've reviewed your conversation..."

8. **Human agent sends messages** directly through Redis pub/sub. No more AI. Direct human-to-human channel.
